<?php

namespace KubernetesPfSenseController\Plugin;

/**
 * Common code for DNS plugins
 *
 * Trait DNSResourceTrait
 * @package KubernetesPfSenseController\Plugin
 */
trait DNSResourceTrait
{
    /**
     * Update pfSense state
     *
     * @return bool
     */
    public function doAction()
    {
        $pluginConfig = $this->getConfig();
        $dnsmasqEnabled = $pluginConfig['dnsBackends']['dnsmasq']['enabled'];
        $unboundEnabled = $pluginConfig['dnsBackends']['unbound']['enabled'];

        // only supported options move along
        if (!$dnsmasqEnabled && !$unboundEnabled) {
            $this->log('plugin enabled without valid dnsBackends');
            return true;
        }

        $resourceHosts = [];
        foreach ($this->state['resources'] as $resource) {
            $this->buildResourceHosts($resourceHosts, $resource);
        }

        $hosts = [];
        $managedHostsPreSave = [];
        foreach ($resourceHosts as $hostName => $struct) {
            $ip = $struct['ip'];
            $this->log("setting hostname entry: Host - ${hostName}, IP - ${ip}");
            $managedHostsPreSave[$hostName] = [
                'resource' => $this->getKubernetesResourceDetails($struct['resource']),
            ];
            $hosts[] = [
                'host' => explode('.', $hostName, 2)[0],
                'domain' => explode('.', $hostName, 2)[1],
                'ip' => $ip,
                'descr' => 'created by kpc - do not edit',
                'aliases' => '',
            ];
        }

        try {
            // get store data
            $store = $this->getStore();
            $managedHosts = $store['managed_hosts'];
            if (empty($managedHosts)) {
                $managedHosts = [];
            }

            // actually remove them from config
            $toDeleteHosts = array_diff(@array_keys($managedHosts), @array_keys($managedHostsPreSave));
            foreach ($toDeleteHosts as $hostName) {
                $this->log("deleting hostname entry for host: ${hostName}");
            }

            $dnsmasqConfig = null;
            $unboundConfig = null;

            if ($dnsmasqEnabled) {
                $dnsmasqConfig = PfSenseConfigBlock::getRootConfigBlock($this->getController()->getRegistryItem('pfSenseClient'), 'dnsmasq');
                if (!is_array($dnsmasqConfig->data['hosts'])) {
                    $dnsmasqConfig->data['hosts'] = [];
                }
                foreach ($hosts as $host) {
                    Utils::putListItemMultiKey($dnsmasqConfig->data['hosts'], $host, ['host', 'domain']);
                }

                foreach ($toDeleteHosts as $hostName) {
                    $itemId = [
                        'host' => explode('.', $hostName, 2)[0],
                        'domain' => explode('.', $hostName, 2)[1],
                    ];
                    Utils::removeListItemMultiKey($dnsmasqConfig->data['hosts'], $itemId, ['host', 'domain']);
                }
            }

            if ($unboundEnabled) {
                $unboundConfig = PfSenseConfigBlock::getRootConfigBlock($this->getController()->getRegistryItem('pfSenseClient'), 'unbound');
                if (!is_array($unboundConfig->data['hosts'])) {
                    $unboundConfig->data['hosts'] = [];
                }
                foreach ($hosts as $host) {
                    Utils::putListItemMultiKey($unboundConfig->data['hosts'], $host, ['host', 'domain']);
                }

                foreach ($toDeleteHosts as $hostName) {
                    $itemId = [
                        'host' => explode('.', $hostName, 2)[0],
                        'domain' => explode('.', $hostName, 2)[1],
                    ];
                    Utils::removeListItemMultiKey($unboundConfig->data['hosts'], $itemId, ['host', 'domain']);
                }
            }

            if ($dnsmasqEnabled && !empty($dnsmasqConfig)) {
                $this->savePfSenseConfigBlock($dnsmasqConfig);
                $this->reloadDnsmasq();
            }

            if ($unboundEnabled && !empty($unboundConfig)) {
                $this->savePfSenseConfigBlock($unboundConfig);
                $this->reloadUnbound();
            }

            // save data to store
            $store['managed_hosts'] = $managedHostsPreSave;
            $this->saveStore($store);

            // reload DHCP sesrvice
            $this->reloadDHCP();

            return true;
        } catch (\Exception $e) {
            $this->log('failed update/reload: '.$e->getMessage().' ('.$e->getCode().')');
            return false;
        }
    }

    /**
     * Does a sanity check to prevent over-aggressive updates when watch resources are technically
     * modified but the things we care about are not
     *
     * @param $event
     * @param $oldItem
     * @param $item
     * @param $stateKey
     * @param $options
     * @return bool
     */
    public function shouldTriggerFromWatchUpdate($event, $oldItem, $item, $stateKey, $options = [])
    {
        // will be NULL for ADDED and DELETED
        if ($oldItem === null) {
            return true;
        }
        $oldResourceHosts = [];
        $newResourceHosts = [];

        $this->buildResourceHosts($oldResourceHosts, $oldItem);
        $this->buildResourceHosts($newResourceHosts, $item);

        foreach ($oldResourceHosts as $host => $value) {
            $oldResourceHosts[$host] = ['ip' => $value['ip']];
        }

        foreach ($newResourceHosts as $host => $value) {
            $newResourceHosts[$host] = ['ip' => $value['ip']];
        }

        if (md5(json_encode($oldResourceHosts)) != md5(json_encode($newResourceHosts))) {
            return true;
        }
        return false;
    }
}

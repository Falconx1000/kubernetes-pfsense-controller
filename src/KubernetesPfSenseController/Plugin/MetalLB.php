<?php

namespace KubernetesPfSenseController\Plugin;

/**
 * Purpose of plugin is to sync cluster node changes to the appropriate bgp implementation configuration.
 *
 * Class MetalLb
 * @package KubernetesPfSenseController\Plugin
 */
class MetalLB extends PfSenseAbstract
{
    use CommonTrait;
    /**
     * Unique plugin ID
     */
    public const PLUGIN_ID = 'metallb';

    /**
     * Init the plugin
     *
     * @throws \Exception
     */
    public function init()
    {
        $controller = $this->getController();
        $pluginConfig = $this->getConfig();
        $nodeLabelSelector = $pluginConfig['nodeLabelSelector'] ?? null;
        $nodeFieldSelector = $pluginConfig['nodeFieldSelector'] ?? null;
        $configMap = $pluginConfig['configMap'] ?? "metallb-system/config";
        $configMapNamespace = explode("/", $configMap)[0];
        $configMapName = explode("/", $configMap)[1];

        // metallb config
        $watch = $controller->getKubernetesClient()->createWatch("/api/v1/watch/namespaces/${configMapNamespace}/configmaps/${configMapName}", [], $this->getMetalLbConfigWatchCallback());
        $this->addWatch($watch);

        // initial load of nodes
        $params = [
            'labelSelector' => $nodeLabelSelector,
            'fieldSelector' => $nodeFieldSelector,
        ];
        $nodes = $controller->getKubernetesClient()->createList('/api/v1/nodes', $params)->get();
        $this->state['nodes'] = $nodes['items'];

        // watch for node changes
        $params = [
            'labelSelector' => $nodeLabelSelector,
            'fieldSelector' => $nodeFieldSelector,
            'resourceVersion' => $nodes['metadata']['resourceVersion'],
        ];
        $watch = $controller->getKubernetesClient()->createWatch('/api/v1/watch/nodes', $params, $this->getNodeWatchCallback('nodes'));
        $this->addWatch($watch);
        $this->delayedAction();
    }

    /**
     * Callback for the metallb configmqp
     *
     * @return \Closure
     */
    private function getMetalLbConfigWatchCallback()
    {
        return function ($event, $watch) {
            $this->logEvent($event);
            switch ($event['type']) {
                case 'ADDED':
                case 'MODIFIED':
                    $this->state['metallb-config'] = yaml_parse($event['object']['data']['config']);
                    $this->delayedAction();
                    break;
                case 'DELETED':
                    $this->state['metallb-config'] = null;
                    break;
            }
        };
    }

    /**
     * Deinit the plugin
     */
    public function deinit()
    {
    }

    /**
     * Pre read watches
     */
    public function preReadWatches()
    {
    }

    /**
     * Post read watches
     */
    public function postReadWatches()
    {
    }

    /**
     * Update pfSense state
     *
     * @return bool
     */
    public function doAction()
    {
        $metalConfig = $this->state['metallb-config'] ?? [];
        $pluginConfig = $this->getConfig();

        if (empty($metalConfig)) {
            return false;
        }

        switch ($pluginConfig['bgp-implementation']) {
            case 'openbgp':
            case 'frr':
                return $this->doActionGeneric();
                break;
            default:
                $this->log('unsupported bgp-implementation: '.$pluginConfig['bgp-implementation']);
                return false;
                break;
        }
    }

    /**
     * Update pfSense state for bgp implementation
     *
     * @return bool
     */
    private function doActionGeneric()
    {
        $metalConfig = $this->state['metallb-config'];
        $pluginConfig = $this->getConfig();

        switch ($pluginConfig['bgp-implementation']) {
            case 'openbgp':
                $bgpConfig = PfSenseConfigBlock::getInstalledPackagesConfigBlock($this->getController()->getRegistryItem('pfSenseClient'), 'openbgpdneighbors');
                break;
            case 'frr':
                $bgpConfig = PfSenseConfigBlock::getInstalledPackagesConfigBlock($this->getController()->getRegistryItem('pfSenseClient'), 'frrbgpneighbors');
                break;
            default:
                $this->log('unsupported bgp-implementation: '.$pluginConfig['bgp-implementation']);
                return false;
                break;
        }

        if (empty($bgpConfig->data)) {
            $bgpConfig->data = [];
        }

        if (empty($bgpConfig->data['config'])) {
            $bgpConfig->data['config'] = [];
        }

        $bgpEnabled = false;
        foreach ($metalConfig['address-pools'] as $pool) {
            if ($pool['protocol'] == 'bgp') {
                $bgpEnabled = true;
                break;
            }
        }

        if ($bgpEnabled) {
            // add/remove as necessary
            $template = $pluginConfig['options'][$pluginConfig['bgp-implementation']]['template'];
            switch ($pluginConfig['bgp-implementation']) {
                case 'openbgp':
                    $defaults = [
                        'md5sigkey' => (string) $template['md5sigkey'],
                        'md5sigpass' => (string) $template['md5sigpass'],
                        'groupname' => (string) $template['groupname'],
                    ];
                    $template = array_merge($defaults, $template);
                    $template = array_map(function ($v) {
                        return $v ?: '';
                    }, $template);
                    break;
                case 'frr':
                    $defaults = [
                        "sendcommunity" => "disabled",
                    ];
                    $template = array_merge($defaults, $template);
                    $template = array_map(function ($v) {
                        return $v ?: '';
                    }, $template);
                    break;
            }

            $nodes = $this->state['nodes'];
            $neighbors = [];
            $managedNeighborsPreSave = [];
            foreach ($nodes as $node) {
                $host = 'kpc-'.KubernetesUtils::getNodeIp($node);
                $managedNeighborsPreSave[$host] = [
                    'resource' => $this->getKubernetesResourceDetails($node),
                ];
                $neighbor = $template;

                switch ($pluginConfig['bgp-implementation']) {
                    case 'openbgp':
                        $neighbor['descr'] = $host;
                        $neighbor['neighbor'] = KubernetesUtils::getNodeIp($node);
                        break;
                    case 'frr':
                        $neighbor['descr'] = $host;
                        $neighbor['peer'] = KubernetesUtils::getNodeIp($node);
                        break;
                }

                $neighbors[] = $neighbor;
            }

            // get store data
            $store = $this->getStore();
            if (empty($store)) {
                $store = [];
            }

            $store[$pluginConfig['bgp-implementation']] = $store[$pluginConfig['bgp-implementation']] ?? [];
            $store[$pluginConfig['bgp-implementation']]['managed_neighbors'] = $store[$pluginConfig['bgp-implementation']]['managed_neighbors'] ?? [];

            $managedNeighborNamesPreSave = @array_keys($managedNeighborsPreSave);
            $managedNeighborNames = @array_keys($store[$pluginConfig['bgp-implementation']]['managed_neighbors']);
            if (empty($managedNeighborNames)) {
                $managedNeighborNames = [];
            }

            // update config with new/updated items
            foreach ($neighbors as $neighbor) {
                Utils::putListItem($bgpConfig->data['config'], $neighbor, 'descr');
            }

            // remove items from config
            $toDeleteItemNames = array_diff($managedNeighborNames, $managedNeighborNamesPreSave);
            foreach ($toDeleteItemNames as $itemId) {
                Utils::removeListItem($bgpConfig->data['config'], $itemId, 'descr');
            }

            // prep config for save
            if (empty($bgpConfig->data['config'])) {
                $bgpConfig->data = null;
            }

            // save newly managed configuration
            try {
                $this->savePfSenseConfigBlock($bgpConfig);
                switch ($pluginConfig['bgp-implementation']) {
                    case 'openbgp':
                        $this->reloadOpenbgp();
                        break;
                    case 'frr':
                        $this->reloadFrrBgp();
                        break;
                }
                $store[$pluginConfig['bgp-implementation']]['managed_neighbors'] = $managedNeighborsPreSave;
                $this->saveStore($store);

                return true;
            } catch (\Exception $e) {
                $this->log('failed update/reload: '.$e->getMessage().' ('.$e->getCode().')');
                return false;
            }
        } else {
            //remove any nodes from config
            // get storage data
            $store = $this->getStore();
            $managedNeighborNames = @array_keys($store[$pluginConfig['bgp-implementation']]['managed_neighbors']);
            if (empty($managedNeighborNames)) {
                return true;
            }

            foreach ($managedNeighborNames as $itemId) {
                Utils::removeListItem($bgpConfig->data['config'], $itemId, 'descr');
            }

            // save newly managed configuration
            try {
                $this->savePfSenseConfigBlock($bgpConfig);
                switch ($pluginConfig['bgp-implementation']) {
                    case 'openbgp':
                        $this->reloadOpenbgp();
                        break;
                    case 'frr':
                        $this->reloadFrrBgp();
                        break;
                }
                $store[$pluginConfig['bgp-implementation']]['managed_neighbors'] = [];
                $this->saveStore($store);

                return true;
            } catch (\Exception $e) {
                $this->log('failed update/reload: '.$e->getMessage().' ('.$e->getCode().')');
                return false;
            }
        }
    }
}

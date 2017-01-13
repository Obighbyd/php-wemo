<?php

namespace a15lam\PhpWemo\Devices;

use a15lam\PhpWemo\Contracts\ClientInterface;
use a15lam\PhpWemo\Discovery;
use a15lam\PhpWemo\Workspace as WS;

/**
 * Class BaseDevice
 *
 * @package a15lam\PhpWemo\Devices
 */
class BaseDevice
{
    /** @type string */
    protected $ip = null;

    /** @type \a15lam\PhpWemo\WemoClient|null */
    protected $client = null;

    /** @type array */
    protected $services = [];

    protected $id = null;

    /**
     * BaseDevice constructor.
     *
     * @param string $id Device ip or id
     * @param ClientInterface   $client
     */
    public function __construct($id, $client)
    {
        $this->id = $id;
        $this->ip = (self::isIp($id)) ? $id : static::getDeviceIpById($id);
        $this->client = $client;
    }

    /**
     * @param string $resource
     *
     * @return array|string
     */
    public function info($resource = 'setup.xml')
    {
        return $this->client->info($resource);
    }

    /**
     * @param bool $refresh
     *
     * @return mixed
     * @throws \Exception
     */
    public function getUDN($refresh = false)
    {
        if ($refresh === false) {
            $device = Discovery::lookupDevice('ip', $this->ip);
            if (isset($device['UDN'])) {
                return $device['UDN'];
            }
        }

        $rs = $this->info();

        if (is_array($rs) && isset($rs['root'])) {
            return $rs['root']['device']['UDN'];
        }

        throw new \Exception('UDN not found for device with ip address ' . $this->ip);
    }

    /**
     * @param $state
     *
     * @return array|string
     * @throws \Exception
     */
    protected function setBinaryState($state)
    {
        $service = $this->services['BridgeService']['serviceType'];
        $controlUrl = $this->services['BridgeService']['controlURL'];
        $method = 'SetBinaryState';
        $arguments = [
            'BinaryState' => $state
        ];

        $rs = $this->client->request($controlUrl, $service, $method, $arguments);
        if(!empty($rs)) {
            $rs = $this->unwrapResponse($rs);

            if (isset($rs['s:Fault'])) {
                throw new \Exception('Failed to change switch state. ' . print_r($rs, true));
            }

            return $rs;
        } else {
            $this->saveState($state);

            return true;
        }
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    protected function getBinaryState()
    {
        $service = $this->services['BridgeService']['serviceType'];
        $controlUrl = $this->services['BridgeService']['controlURL'];
        $method = 'GetBinaryState';

        $rs = $this->client->request($controlUrl, $service, $method);

        if(!empty($rs)) {
            $rs = $this->unwrapResponse($rs);

            if (isset($rs['s:Fault'])) {
                throw new \Exception('Failed to change switch state. ' . print_r($rs, true));
            }

            return $rs['u:GetBinaryStateResponse']['BinaryState'];
        } else {
            return $this->getState();
        }
    }

    /**
     * @param array $response
     *
     * @return mixed
     * @throws \Exception
     */
    protected function unwrapResponse(array $response)
    {
        try {
            return $response['s:Envelope']['s:Body'];
        } catch (\Exception $e) {
            throw new \Exception('Failed to unwrap response. ' .
                $e->getMessage() .
                ' Response: ' .
                print_r($response, true));
        }
    }

    /**
     * @param $ip
     *
     * @return bool
     */
    public static function isIp($ip)
    {
        $segments = explode('.', $ip);

        if (count($segments) === 4) {
            foreach ($segments as $segment) {
                if (!is_numeric($segment)) {
                    return false;
                }
            }

            return true;
        } else {
            return false;
        }
    }

    /**
     * @param $id
     *
     * @return mixed
     * @throws \Exception
     */
    public static function getDeviceIpById($id)
    {
        $device = Discovery::lookupDevice('id', $id);
        if (isset($device['ip'])) {
            return $device['ip'];
        }
        throw new \Exception('Invalid device id supplied. No device found by id ' . $id);
    }

    public function saveState($state)
    {
        try {
            $data = [];
            $file = (Discovery::$deviceFile !== null) ? Discovery::$deviceFile : WS::config()->get('device_storage');
            $content = @file_get_contents($file);
            if (!empty($content)) {
                $data = json_decode($content, true);
            }
            if(!isset($data['state'])){
                $data['state'] = [];
            }
            $data['state'][$this->id] = $state;
            $json = json_encode($data, JSON_UNESCAPED_SLASHES);
            @file_put_contents($file, $json);
        } catch (\Exception $e){
            return false;
        }
    }

    public function getState($all = false)
    {
        $file = (Discovery::$deviceFile !== null) ? Discovery::$deviceFile : WS::config()->get('device_storage');
        $content = @file_get_contents($file);
        if (!empty($content)) {
            $states = json_decode($content, true);
            $states = (isset($states['state']))? $states['state'] : [];

            if(true === $all) {
                return $states;
            }

            return (isset($states[$this->id]))? $states[$this->id] : 0;
        } else {
            if(true === $all){
                return [];
            }

            return 0;
        }
    }
}
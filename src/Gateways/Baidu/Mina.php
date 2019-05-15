<?php
/**
 * @author: mozr
 * @date: 2019/1/7
 */

namespace Runner\NezhaCashier\Gateways\Baidu;

class Mina extends AbstractBaiduGateway
{
    /**
     * @param array $payload
     *
     * @return array
     */
    protected function doCharge(array $payload): array
    {
        return [
            'charge_url' => '',
            'parameters' => $payload,
        ];
    }

    /**
     * @return string
     */
    public function success(): string
    {
        return json_encode([
            'errno' => 0,
            'msg'   => 'success',
            'data'  => [
                'isConsumed' => 2
            ]
        ], true);
    }
}

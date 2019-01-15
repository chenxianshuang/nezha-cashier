<?php
/**
 * Created by PhpStorm.
 * User: hedao
 * EMAIL: 896945246@qq.com
 * Date: 2019/1/14
 * Time: 16:56
 */

namespace Runner\NezhaCashier\Gateways\Alipayoversea;
use Runner\NezhaCashier\Requests\Charge;

class Wap extends AbstractAlipayoverseaGateway
{
    public function doCharge(array $response, Charge $form): array
    {
        return [
            'charge_url' => $response['pay_url'],
            'parameters' => [],
        ];
    }

    protected function prepareCharge(Charge $form): array
    {
        return [];
    }
}
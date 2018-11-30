<?php
/**
 * Created by PhpStorm.
 * User: hedao
 * EMAIL: 896945246@qq.com
 * Date: 2018/11/28
 * Time: 10:55
 */

namespace Runner\NezhaCashier\Gateways\Converge;

use Runner\NezhaCashier\Requests\Charge;

class H5 extends AbstractConvergeGateway
{

    protected function prepareCharge(Charge $form): array
    {
        return ['q1_FrpCode' => $this->config->get('type')];
    }

    protected function doCharge(array $response, Charge $form): array
    {

        if (preg_match('/location\.href=[\'\"](.*?)[\'\"]/', $response['rc_Result'], $match)) {
            $response['rc_Result'] = $match[1];
        }

        return [
            'charge_url' => $response['rc_Result'],
            'parameters' => [],
        ];
    }
}

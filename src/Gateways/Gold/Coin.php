<?php
/**
 * Created by PhpStorm.
 * User: zc
 * Date: 2019/07/30
 * Time: 下午 12:21
 * Email:1297814479@qq.com
 */
namespace Runner\NezhaCashier\Gateways\Gold;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Runner\NezhaCashier\Requests\Charge;
use Runner\NezhaCashier\Utils\HttpClient;

class Coin extends AbstractGoldGateway
{
    const CONTENT_TYPE = 'application/x-www-form-urlencoded';
    const CONSUME_HOST = 'gold-ingot.lingjiptai.com';

    /**
     * @param array $response
     * @param Charge $form
     * @return array
     * @author zc
     * @time 2019/08/02
     */
    protected function doCharge(array $response, Charge $form): array
    {
        $parameters = [
            'subject' => $form['subject'],
            'amount' => $form['amount'] / 10,
            'payment_id' => $form['order_id'],
            'token' => $response['token'],
            'content_type' => self::CONTENT_TYPE,
            'host' => self::CONSUME_HOST,
        ];

        return [
            'charge_url' => $response['url'],
            'parameters' => $parameters,
        ];
    }

    protected function prepareCharge(Charge $form): array
    {
        $token = '';
        if ($form->has('extras.access_token')) {
            $token = $form->get('extras.access_token');
        }
        return [
            'token' => $token,
        ];
    }

    /**
     * @return string
     */
    public function success(): string
    {
        return json_encode([
            'errno' => 0,
            'msg' => 'success',
        ], true);
    }
}
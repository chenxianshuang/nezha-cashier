<?php
/**
 * Created by PhpStorm.
 * User: hedao
 * EMAIL: 896945246@qq.com
 * Date: 2019/8/21
 * Time: 11:47
 */

namespace Runner\NezhaCashier\Gateways\Wechatkx;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Runner\NezhaCashier\Exception\RequestGatewayException;
use Runner\NezhaCashier\Exception\WechatOpenIdException;
use Runner\NezhaCashier\Requests\Charge;
use Runner\NezhaCashier\Utils\HttpClient;

class Official extends AbstractWechatkxGateway
{
    const JSAPI_AUTH_URL = 'https://api.weixin.qq.com/sns/oauth2/access_token';

    /**
     * @param Charge $form
     *
     * @return array
     */
    protected function prepareCharge(Charge $form): array
    {
        $openId = $form->has('extras.open_id')
            ? $form->get('extras.open_id')
            : $this->getOpenId($form->get('extras.code'));

        return [
            'sub_openid' => $openId,
        ];
    }

    protected function doCharge(array $response, Charge $form): array
    {
        $parameters = [
            'appId'     => $response['appId'],
            'timeStamp' => $response['timeStamp'],
            'nonceStr'  => $response['nonceStr'],
            'package'   => $response['package'],
            'signType'  => $response['signType'],
            'paySign'   => $response['paySign'],
        ];

        return [
            'charge_url' => '',
            'parameters' => $parameters,
        ];
    }

    protected function getTradeType(): string
    {
        return 'comm.js.pay';
    }

    protected function getOpenId($code): string
    {
        $parameters = [
            'appid'      => $this->config->get('app_id'),
            'secret'     => $this->config->get('app_secret'),
            'code'       => $code,
            'grant_type' => 'authorization_code',
        ];

        return HttpClient::request(
            'GET',
            static::JSAPI_AUTH_URL,
            [
                RequestOptions::QUERY => $parameters,
            ],
            function (ResponseInterface $response) {
                $result = json_decode($response->getBody(), true);

                if (isset($result['errcode'])) {
                    throw new WechatOpenIdException($result['errmsg']);
                }

                return $result['openid'];
            },
            function (RequestException $exception) {
                throw new RequestGatewayException('Wechatkx Gateway Error', $exception);
            }
        );
    }
}

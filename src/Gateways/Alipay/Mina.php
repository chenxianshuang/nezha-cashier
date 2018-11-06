<?php
/**
 * Created by PhpStorm.
 * User: hedao
 * EMAIL: 896945246@qq.com
 * Date: 2018/11/5
 * Time: 10:23
 */

namespace Runner\NezhaCashier\Gateways\Alipay;

use Runner\NezhaCashier\Requests\Charge;
use Runner\NezhaCashier\Utils\HttpClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

class Mina extends AbstractAlipayGateway
{
    /**
     * @return string
     */
    protected function getChargeMethod(): string
    {
        return 'alipay.trade.create';
    }

    /**
     * @param Charge $form
     * @return mixed
     */
    protected function prepareCharge(Charge $form): array
    {
        $buyerId = $form->has('extras.alipay_user_id')
            ? $form->get('extras.alipay_user_id')
            : $this->getBuyerId($form->get('extras.alipay_code'));

        return [
            'buyer_id' => $buyerId,
        ];
    }


    /**
     * @param array $payload
     * @return mixed
     */
    protected function doCharge(array $payload): array
    {
        $response = $this->request($payload);

        return [
            'charge_url' => '',
            'parameters' => [
                'trade_no' => $response['trade_no']
            ]
        ];
    }

    /**
     * @param $code
     * @return string
     */
    protected function getBuyerId($code): string
    {
        $parameters = [
            'app_id' => $this->config->get('app_id'),
            'method' => 'alipay.system.oauth.token',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date("Y-m-d H:i:s"),
            'version' => '1.0',
            'grant_type' => 'authorization_code',
            'code' => $code
        ];

        $parameters['sign'] = $this->sign($parameters);

        return HttpClient::request(
            'POST',
            self::OPENAPI_GATEWAY,
            [
                RequestOptions::FORM_PARAMS => $parameters,
            ],
            function (ResponseInterface $response) use ($parameters) {
                $result = json_decode(mb_convert_encoding($response->getBody(), 'utf-8', 'gb2312'), true);

                $index = str_replace('.', '_', $parameters['method']) . '_response';

                //成功响应没有code
                if (isset($result[$index]['code'])) {
                    throw new GatewayException(
                        sprintf(
                            'Alipay Gateway Error: %s, code: %s, sub_code: %s, sub_msg: %s',
                            $result[$index]['msg'],
                            $result[$index]['code'],
                            $result[$index]['sub_code'],
                            $result[$index]['code']
                        ),
                        $result
                    );
                }

                return $result[$index]['user_id'];
            },
            function (RequestException $exception) {
                throw new RequestGatewayException('Alipay Gateway Error', $exception);
            }
        );
    }

}
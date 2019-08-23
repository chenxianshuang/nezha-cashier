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
use Runner\NezhaCashier\Exception\GatewayException;
use Runner\NezhaCashier\Exception\RequestGatewayException;
use Runner\NezhaCashier\Gateways\AbstractGateway;
use Runner\NezhaCashier\Requests\Charge;
use Runner\NezhaCashier\Requests\Close;
use Runner\NezhaCashier\Requests\Query;
use Runner\NezhaCashier\Requests\Refund;
use Runner\NezhaCashier\Utils\Amount;
use Runner\NezhaCashier\Utils\HttpClient;

abstract class AbstractWechatkxGateway extends AbstractGateway
{
    const MCH_APPLY_ORDER = 'http://api2.lfwin.com/payapi/mini/wxpay';

    /**
     * 支付.
     *
     * @param Charge $form
     *
     * @return array
     */
    public function charge(Charge $form): array
    {
        $payload = $this->createPayload(
            array_merge(
                [
                    'money' => Amount::centToDollar($form->get('amount')),
                    'notify_url' => $this->config->get('notify_url'),
                    'remarks' => $form->get('description'),
                    'service' => $this->getTradeType(),
                    'mch_orderid' => $form->get('order_id'),
                ],
                $this->prepareCharge($form)
            )
        );

        $response = $this->request(self::MCH_APPLY_ORDER, $payload);

        return $this->doCharge($response, $form);
    }

    /**
     * 退款.
     *
     * @param Refund $form
     *
     * @return array
     */
    public function refund(Refund $form): array
    {
        // TODO
    }

    /**
     * 关闭.
     *
     * @param Close $form
     *
     * @return array
     */
    public function close(Close $form): array
    {
        // TODO
    }

    /**
     * 查询.
     *
     * @param Query $form
     *
     * @return array
     */
    public function query(Query $form): array
    {
        // TODO
    }

    /**
     * 支付通知, 触发通知根据不同支付渠道, 可能包含:
     * 1. 交易创建通知
     * 2. 交易关闭通知
     * 3. 交易支付通知.
     *
     * @param $receives
     *
     * @return array
     */
    public function chargeNotify(array $receives): array
    {
        return [
            'order_id' => $receives['mch_orderid'],
            'status' => $this->formatTradeStatus($receives['paystatus']), // 微信只推送支付完成
            'trade_sn' => $receives['trade_no'],
            'buyer_identifiable_id' => '',
            'buyer_is_subscribed' => 'no',
            'amount' => Amount::dollarToCent($receives['pri_paymoney']),
            'buyer_name' => $receives['buyer_account'],
            'paid_at' => (isset($receives['paytime']) ? strtotime($receives['paytime']) : 0),
            'raw' => $receives,
        ];
    }

    /**
     * 退款通知, 并非所有支付渠道都支持
     *
     * @param $receives
     *
     * @return array
     */
    public function refundNotify(array $receives): array
    {
        // TODO
    }

    /**
     * 关闭通知, 并非所有支付渠道都支持
     *
     * @param $receives
     *
     * @return array
     */
    public function closeNotify(array $receives): array
    {
        // TODO
    }

    /**
     * 通知校验.
     *
     * @param $receives
     *
     * @return bool
     */
    public function verify($receives): bool
    {
        return $receives['sign'] === $this->sign($receives);
    }

    /**
     * 通知成功处理响应.
     *
     * @return string
     */
    public function success(): string
    {
        return 'success';
    }

    /**
     * 通知处理失败响应.
     *
     * @return string
     */
    public function fail(): string
    {
        return 'fail';
    }

    /**
     * @param $receives
     *
     * @return array
     */
    public function convertNotificationToArray($receives): array
    {
        return $receives;
    }

    /**
     * @return string
     */
    public function receiveNotificationFromRequest(): array
    {
        return $_POST;
    }

    /**
     * @param Charge $form
     *
     * @return array
     */
    abstract protected function prepareCharge(Charge $form): array;

    /**
     * @param array $response
     * @param Charge $form
     *
     * @return array
     */
    abstract protected function doCharge(array $response, Charge $form): array;

    /**
     * @return string
     */
    abstract protected function getTradeType(): string;

    /**
     * @param array $payload
     *
     * @return array
     */
    protected function createPayload(array $payload)
    {
        $payload = array_merge(
            [
                'sub_appid' => $this->config->get('app_id'),
                'apikey' => $this->config->get('mch_id'),
                'nonce_str' => uniqid(),
            ],
            $payload
        );

        $payload['sign'] = $this->sign($payload);

        return $payload;
    }

    /**
     * @param array $parameters
     *
     * @return string
     */
    protected function sign(array $parameters): string
    {
        unset($parameters['sign']);
        ksort($parameters);
        reset($parameters);
        $parameters['signkey'] = $this->config->get('mch_secret');

        $sign = '';
        foreach ($parameters as $k => $v) {
            if (is_array($v)) {
                $v = json_encode($v);
            }
            $sign .= $k . "=" . $v . "&";
        }

        return md5(trim($sign, '&'));
    }

    /**
     * @param $url
     * @param array $payload
     * @param null $cert
     * @param null $sslKey
     *
     * @return array
     */
    protected function request($url, array $payload, $cert = null, $sslKey = null): array
    {
        $options = [
            'form_params' => $payload,
        ];
        if (!is_null($cert)) {
            $options[RequestOptions::CERT] = $cert;
            $options[RequestOptions::SSL_KEY] = $sslKey;
        }

        return HttpClient::request(
            'POST',
            $url,
            $options,
            function (ResponseInterface $response) {

                $result = json_decode((string)$response->getBody(), true);

                if (isset($result['status']) && $result['status'] != 10000) {
                    throw new GatewayException(
                        sprintf(
                            'Wechatkx Gateway Error: %s, %s',
                            $result['status'] ?? '',
                            $result['message'] ?? ''
                        ),
                        $result
                    );
                }

                return $result;
            },
            function (RequestException $exception) {
                throw new RequestGatewayException('Wechathx Gateway Error.', $exception);
            }
        );
    }

    /**
     * @param $status
     *
     * @return string
     */
    protected function formatTradeStatus($status): string
    {
        switch ($status) {
            case '2':
                return 'created';
            default:
                return 'paid';
        }
    }
}

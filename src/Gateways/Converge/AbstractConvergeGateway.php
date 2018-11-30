<?php
/**
 * Created by PhpStorm.
 * User: hedao
 * EMAIL: 896945246@qq.com
 * Date: 2018/11/28
 * Time: 10:55
 */

namespace Runner\NezhaCashier\Gateways\Converge;

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

abstract class AbstractConvergeGateway extends AbstractGateway
{
    const COMMON_CHARGE = 'https://www.joinpay.com/trade/uniPayApi.action';

    const COMMON_QUERY = 'https://www.joinpay.com/trade/queryOrder.action';

    const COMMON_REFUND = 'https://www.joinpay.com/trade/refund.action';

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
                    'p0_Version'             => '1.0',
                    'p2_OrderNo'             => $form->get('order_id'),
                    'p3_Amount'              => Amount::centToDollar($form->get('amount')),
                    'p4_Cur'                 => 1,
                    'p5_ProductName'         => $form->get('subject'),
                    'p6_ProductDesc'         => $form->get('description'),
                    'p8_ReturnUrl'           => $form->get('return_url'),
                    'p9_NotifyUrl'           => $this->config->get('notify_url'),
                ],
                $this->prepareCharge($form)
            )
        );

        $response = $this->request(self::COMMON_CHARGE, $payload);

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
        $payload = $this->createPayload(
            array_merge(
                [
                    'p2_OrderNo' => $form->get('order_id'),
                ]
            )
        );

        $result = $this->request(self::COMMON_QUERY, $payload);

        return [
            'order_id'              => $result['r2_OrderNo'],
            'status'                => $this->formatTradeStatus($result['ra_Status']),
            'trade_sn'              => $result['r5_TrxNo'],
            'amount'                => $result['r3_Amount'],
            'raw'                   => $result,
        ];
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
            'order_id'              => $receives['r2_OrderNo'],
            'status'                => $this->formatTradeStatus($receives['r6_Status']),
            'trade_sn'              => $receives['r7_TrxNo'],
            'amount'                => Amount::dollarToCent($receives['r3_Amount']),
            'paid_at'               => strtotime($receives['ra_PayTime']),
            'raw'                   => $receives,
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
        $receives = $this->convertNotificationToArray($receives);

        return $receives['hmac'] === $this->sign($receives);
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
        $receives = json_decode($receives, true);
        foreach ($receives as $key => $value) {
            $receives[$key] = urldecode($value);
        }
        return $receives;
    }

    /**
     * @return string
     */
    public function receiveNotificationFromRequest(): string
    {
        return json_encode($_GET);
    }

    /**
     * @param Charge $form
     *
     * @return array
     */
    abstract protected function prepareCharge(Charge $form): array;

    /**
     * @param array  $response
     * @param Charge $form
     *
     * @return array
     */
    abstract protected function doCharge(array $response, Charge $form): array;


    /**
     * @param array $payload
     *
     * @return array
     */
    protected function createPayload(array $payload)
    {
        $payload = array_merge(
            [
                'p1_MerchantNo'     => $this->config->get('mch_id'),
            ],
            $payload
        );
        $payload['hmac'] = $this->sign($payload);

        return $payload;
    }

    /**
     * @param array $parameters
     *
     * @return string
     */
    protected function sign(array $parameters): string
    {
        unset($parameters['hmac']);

        $arrays = [];
        foreach ($parameters as $key => $value) {
            if (preg_match('/^([pqr]{1})(\w+)_/', $key, $match) && !empty($value)) {
                switch (strtolower($match[1])) {
                    case 'p':
                        $arrays[0][$match[2]] = $value;
                        break;
                    case 'q':
                        $arrays[1][$match[2]] = $value;
                        break;
                    case 'r':
                        $arrays[2][$match[2]] = $value;
                        break;
                    default :
                        $arrays[3][$match[2]] = $value;
                }
            }
        }


        $str = '';
        foreach ($arrays as $key => $array) {
            ksort($array, SORT_STRING);
            foreach ($array as $value) {
                $str .= $value;
            }
        }

        return md5($str . $this->config->get('mch_secret'));
    }


    /**
     * @param $url
     * @param array $payload
     * @param null  $cert
     * @param null  $sslKey
     *
     * @return array
     */
    protected function request($url, array $payload, $method = 'POST'): array
    {
        return HttpClient::request(
            $method,
            $url,
            [
                RequestOptions::FORM_PARAMS => $payload,
            ],
            function (ResponseInterface $response) {

                $result = json_decode((string) $response->getBody(), true);

                //ra_Code + rb_CodeMsg || rb_Code + rc_CodeMsg
                if ((isset($result['ra_Code']) && !in_array($result['ra_Code'], [100, 102]))
                    ||
                    (isset($result['rb_Code']) && !in_array($result['rb_Code'], [100, 102]))
                ) {
                    throw new GatewayException(
                        sprintf(
                            'Converge Gateway Error: %s, %s',
                            $result['rb_CodeMsg'] ?? ($result['rc_CodeMsg'] ?? ''),
                            $result['ra_Code'] ?? ($result['rb_Code'] ?? '')
                        ),
                        $result
                    );
                }

                return $result;
            },
            function (RequestException $exception) {
                throw new RequestGatewayException('Converge Gateway Error.', $exception);
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
        // 100成功，101失败，102已创建，103已取消
        switch ($status) {
            case '100':
                return 'paid';
            case '103':
                return 'closed';
            default:
                return 'created';
        }
    }
}

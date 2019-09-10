<?php
/**
 * Created by PhpStorm.
 * User: zc
 * Date: 2019/07/18
 * Time: 下午 3:08
 * Email:1297814479@qq.com
 */
namespace Runner\NezhaCashier\Gateways\Gold;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Runner\NezhaCashier\Exception\GatewayException;
use Runner\NezhaCashier\Exception\RequestGatewayException;
use Runner\NezhaCashier\Gateways\AbstractGateway;
use Runner\NezhaCashier\Requests\Charge;
use Runner\NezhaCashier\Requests\Close;
use Runner\NezhaCashier\Requests\Query;
use Runner\NezhaCashier\Requests\Refund;
use Runner\NezhaCashier\Utils\HttpClient;

abstract class AbstractGoldGateway extends AbstractGateway
{
    const REQUEST_GOLD = 'http://gold-ingot.lingjiptai.com/payment';
    const CONTENT_TYPE = 'application/x-www-form-urlencoded';
    const CONSUME_QUERY_HOST = 'gold-ingot.lingjiptai.com';

    /**
     * 支付
     * @param Charge $form
     * @return array
     * @author zc
     * @time 2019/07/18
     */
    public function charge(Charge $form): array
    {
        $amount = $form->get('amount');
        $payload = $this->createPayload(
            array_merge(
                [
                    'subject' => $form->get('subject'),
                    'amount' => $amount / 10,
                    'order_id' => $form->get('order_id'),
                ],
                $this->prepareCharge($form)
            )
        );
        if (empty($payload['token'])) {
            failed('missing access_token');
        }

        $url = self::REQUEST_GOLD . '/consume';

        $response = [
            'url' => $url,
            'token' => $payload['token']
        ];
       

        return $this->doCharge($response, $form);
    }

    /**
     * 金币退款
     * @param Refund $form
     * @return array
     * @author zc
     * @time 2019/07/19
     */
    public function refund(Refund $form): array
    {

        $payload = $this->createPayload(
            array_merge(
                [
                    'refund_payment_id' => $form->get('order_id'),
                    'refund_amount' => $form->get('refund_amount'),
                    'total_amount' => $form->get('total_amount'),
                ]
            )
        );

        $url = self::REQUEST_GOLD . '/refund';

        $response = $this->request($url, $payload, $this->header());

        return [
            'refund_sn' => $response['new_trade_no'],
            'refund_amount' => $response['refund'] . '00',
            'raw' => $response,
        ];
    }


    /**
     * @return string
     */
    public function success(): string
    {
        return 'success';
    }

    /**
     * @return string
     */
    public function fail(): string
    {
        return 'fail';
    }

    /**
     * @param array $receives
     *
     * @return array
     */
    public function chargeNotify(array $receives): array
    {
        return [
            'order_id' => $receives['payment_id'],
            'status' => $receives['status'],
            'trade_sn' => $receives['trade_sn'],
            'amount' => $receives['amount'] * 10,
            'buyer_identifiable_id' => $receives['buyer_identifiable_id'] ?? '',
            'buyer_name' => '',
            'paid_at' => (isset($receives['paid_at']) ? strtotime($receives['paid_at']) : 0),
            'raw' => $receives['raw'],
        ];
    }

    public function receiveNotificationFromRequest(): array
    {
        return $_POST;
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

    public function verify($receives): bool
    {
        return true;
    }

    /**
     * @param Query $form
     *
     * @return array
     */
    public function query(Query $form): array
    {
        $parameters = [
            'trade_no' => $form->get('payment_id'),
            'payment_id' => $form->get('order_id')
        ];
        $url = $url = self::REQUEST_GOLD . '/record';
        $result = $this->request($url, $parameters, $this->header());

        return [
            'order_id' => $result['order_id'],
            'status' => $result['status'],
            'trade_sn' => $result['trade_sn'] ?? '',
            'buyer_identifiable_id' => $result['buyer_identifiable_id'] ?? '',
            'buyer_is_subscribed' => (isset($result['is_subscribe']) ? ('Y' === $result ? 'yes' : 'no') : 'no'),
            'amount' => $result['amount'],
            'buyer_name' => '',
            'paid_at' => (isset($result['paid_at']) ? strtotime($result['paid_at']) : 0),
            'raw' => $result['raw'],
        ];
    }

    /**
     * @param array $receives
     *
     * @return array
     */
    public function refundNotify(array $receives): array
    {
        // TODO: Implement refundNotify() method.
    }

    /**
     * @param array $receives
     *
     * @return array
     */
    public function closeNotify(array $receives): array
    {
        // TODO: Implement closeNotify() method.
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
        return [];
    }

    abstract protected function prepareCharge(Charge $form): array;

    /**
     * @param array $response
     * @param Charge $form
     *
     * @return array
     */
    abstract protected function doCharge(array $response, Charge $form): array;

    protected function request($url, $params, $header)
    {
        $options = [
            'form_params' => $params,
            'headers' => $header
        ];

        return HttpClient::request(
            'PUT',
            $url,
            $options,
            function (ResponseInterface $response) {
                $result = json_decode($response->getBody(), true);
                $code = $response->getStatusCode();
                if ($code >= 200 && $code < 300) {
                    return $result;
                } else {
                    throw new GatewayException(
                        sprintf(
                            'Gold Gateway Error: %s, %s',
                            $result['err_code'] ?? '',
                            $result['err_msg'] ?? ''
                        ),
                        $result
                    );
                }
            },
            function (RequestException $exception) {
                throw new RequestGatewayException('Gold Gateway Error.', $exception);
            }
        );
    }

    protected function header()
    {
        return [
            'Content-Type' => self::CONTENT_TYPE,
            'Host' => self::CONSUME_QUERY_HOST,
            'x-api-key' => $this->config->all()['x-api-key']
        ];
    }

    protected function createPayload(array $payload)
    {
        return $payload;
    }
    protected function formatTradeStatus($status): string
    {
        return [];
    }

}

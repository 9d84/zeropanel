<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\{
    Coupon,
    Product,
    Setting,
    Order,
    User,
    Payback,
    Ann,
    Payment
};
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use App\Services\PaymentService;
use Pkly\I18Next\I18n;

class OrderController extends BaseController
{
    public function order(ServerRequest $request, Response $response, array $args)
    {
        $this->view()
            ->assign('anns', Ann::where('date', '>=', date('Y-m-d H:i:s', time() - 7 * 86400))->orderBy('date', 'desc')->get())
            ->display('user/order.tpl');
        return $response;
    }

    public function orderDetails(ServerRequest $request, Response $response, array $args)
    {
        $order_no = $args['no'];
        $order = Order::where('user_id', $this->user->id)
            ->where('order_no', $order_no)
            ->first();
        if (!is_null($order->product_id) && $order->order_type != '2') {
            $product = Product::find($order->product_id);
            $product_name = $product->name;
            $order_type = [
                1   =>  I18n::get()->t('purchase product') .  ': ' . $product_name . '-' . $product->productPeriod($order->product_price),
                3   =>  I18n::get()->t('renewal product') .': ' . $product_name . '-' . $product->productPeriod($order->product_price),
                4   =>  I18n::get()->t('upgrade product') .': ' . $product_name . '-' . $product->productPeriod($order->product_price),
            ];
        } else {
            $product_name = '';
            $product = [];
            $order_type = [
                2   =>  I18n::get()->t('add credit') .': ' . $order->order_total,
            ];
        }
        
        $payments = Payment::where('enable', 1)->get();
            $this->view()
                ->assign('anns', Ann::where('date', '>=', date('Y-m-d H:i:s', time() - 7 * 86400))->orderBy('date', 'desc')->get())
                ->assign('order', $order)
                ->assign('product', $product)
                ->assign('order_type', $order_type)
                ->assign('payments', $payments)
                ->display('user/order_detail.tpl');
        return $response;   
    }

    public function createOrder(ServerRequest $request, Response $response, array $args)
    {
        $user          = $this->user;
        $postData      = $request->getParsedBody();
        $type          = $args['type'];

        switch ($type) {
            case 1: // 新购产品
                $coupon_code   = $postData['coupon_code'];
                $product_id    = $postData['product_id'];
                $product_price = $postData['product_price'];
                $product       = Product::find($product_id);
                try {
                    if (Setting::obtain('verify_email') === 'open' && $user->verified === 0) {
                        throw new \Exception(I18n::get()->t('please verify email first'));
                    }
                    if (is_null($product)) {
                        throw new \Exception(I18n::get()->t('error request'));
                    }
                    
                    if (!$product->productPeriod($product_price)) {
                        throw new \Exception(I18n::get()->t('error request'));
                    }
                    
                    if ($user->product_id == $product->id) {                      
                        throw new \Exception('已有该产品，请在主页点击续费');
                    }
                    $coupon = Coupon::where('code', '=', $coupon_code)->first();

                    $order                 = new Order();
                    $order->order_no       = self::createOrderNo();
                    $order->user_id        = $user->id;
                    $order->product_id     = $product->id;
                    $order->order_type     = $type;
                    $order->product_price  = $product_price;
                    $order->product_period = $product->productPeriod($product_price) ?? NULL;
                    $order->order_total    = $product_price;
                    if ($coupon_code != '') {
                        $order->coupon_id   = $coupon->id;
                        $order->order_total = round($product_price * ((100 - $coupon->discount) / 100), 2);
                    }
                    if ($user->money > 0 && $order->order_total > 0) {
                        $remaining_total = $user->money - $order->order_total;
                        if ($remaining_total > 0) {
                            $order->credit_paid = $order->order_total;
                            $order->order_total = 0;
                        } else {
                            $order->credit_paid = $user->money;
                            $order->order_total = $order->order_total - $user->money;
                        }
                    }
                    
                    $order->order_status   = 1;
                    $order->created_time   = time();
                    $order->updated_time   = time();
                    $order->expired_time   = time() + 600;
                    $order->execute_status = 0;
                    $order->save();
                } catch (\Exception $e) {
                    return $response->withJson([
                        'ret' => 0,
                        'msg' => $e->getMessage(),
                    ]);
                }
                break;
            case 2: // 账户充值
                $amount        = $postData['add_credit_amount'];
                try {
                    if ($amount == '') {
                        throw new \Exception(I18n::get()->t('please enter the amount'));
                    }
                    if ($amount <= 0) {
                        throw new \Exception(I18n::get()->t('amount should be greater than zero'));
                    }
                    $order                 = new Order();
                    $order->order_no       = self::createOrderNo();
                    $order->user_id        = $user->id;
                    $order->order_total    = $amount;
                    $order->order_type     = $type;
                    $order->order_status   = 1;
                    $order->created_time   = time();
                    $order->updated_time   = time();
                    $order->expired_time   = time() + 600;
                    $order->execute_status = 0;
                    $order->save();
                } catch (\Exception $e) {
                    return $response->withJson([
                        'ret' => 0,
                        'msg' => $e->getMessage(),
                    ]);
                }
                break;
            case 3: //续费产品
                try {
                    $latest_order = Order::where('user_id', $user->id)->where('order_status', 2)
                        ->where('order_type', 1)->where('product_id', $user->product_id)->latest('paid_time')->first();
                    if (is_null($latest_order)){
                        throw new \Exception('订单不存在');
                    }
                    if (is_null($latest_order->product_period)) {
                        throw new \Exception('订单错误，无法续费');
                    }
                    $product = Product::find($user->product_id);
                    if (is_null($product)) {
                        throw new \Exception('产品已经被删除, 续费失败');
                    }
                    if ($product->renew === 0 && $product->status === 0) {
                        throw new \Exception('该产品不允许续费');
                    }
                    
                    $order                 = new Order;
                    $order->order_no       = OrderController::createOrderNo();
                    $order->order_type     = 3;
                    $order->user_id        = $user->id;
                    $order->product_id     = $latest_order->product_id;
                    $order->product_price  = $latest_order->product_price;
                    $order->product_period = $product->productPeriod($latest_order->product_price);
                    $order->order_total    = $latest_order->order_total + $latest_order->credit_paid;
                    if ($user->money > 0 && $order->order_total > 0) {
                        $remaining_total = $user->money - $order->order_total;
                        if ($remaining_total > 0) {
                            $order->credit_paid = $order->order_total;
                            $order->order_total = 0;
                        } else {
                            $order->credit_paid = $user->money;
                            $order->order_total = $order->order_total - $user->money;
                        }
                    }
                    $order->order_status   = 1;
                    $order->created_time   = time();
                    $order->updated_time   = time();
                    $order->expired_time   = time() + 600;
                    $order->execute_status = 0;
                    $order->save();
                } catch (\Exception $e){
                    return $response->withJson([
                        'ret' => 0,
                        'msg' => $e->getMessage(),
                    ]);
                }
            default:
                break;
        }
        return $response->withJson([
            'ret'      => 1,
            'order_no' => $order->order_no,
        ]);
    }

    public function orderStatus(ServerRequest $request, Response $response, array $args)
    {
        $order_no = $args['no'];
        $order = Order::where('order_no', $order_no)->first();

        return $response->withJson([
            'ret' => 1,
            'status' => $order->order_status,
        ]);
    }

    public function processOrder(ServerRequest $request, Response $response, array $args)
    {
        $user           = $this->user;
        $payment_method = $request->getParsedBodyParam('method');
        $order_no       = $request->getParsedBodyParam('order_no');

        $order = Order::where('user_id', $user->id)->where('order_no', $order_no)->first();
        try {
            if (time() > $order->expired_time) {
                throw new \Exception(I18n::get()->t('order has expired'));
            }
            if ($order->order_status == 2) {
                throw new \Exception(I18n::get()->t('order has paid'));
            }
            
            if ($order->order_total <= 0) {
                if ($user->money < $order->order_total) {
                    throw new \Exception(I18n::get()->t('insufficient credit'));
                }
                
                $user->money -= $order->credit_paid;
                $user->save();
                self::execute($order->order_no);
            } else {
                // 计算结账金额              
                $payment = Payment::find($payment_method);
                $payment_service = new PaymentService($payment->gateway, $payment->id);
                if ($payment->fixed_fee || $payment->percent_fee) {
                    $order->handling_fee = round(($order->order_total * ($payment->percent_fee / 100)) + $payment->fixed_fee, 2);
                }

                if ($payment->recharge_bonus && $order->order_type === 2) {
                    $order->bonus_amount = $order->order_total * ($payment->recharge_bonus / 100);
                }
                
                $currency = Setting::getClass('currency');
                $exchange_rate = $currency['currency_exchange_rate'] ?: 1;
                $order->payment_id = $payment_method;
                $order->save();

                $result = $payment_service->toPay([
                    'order_no'     => $order->order_no,
                    'total_amount' => isset($order->handling_fee) ? round((($order->order_total + $order->handling_fee) * $exchange_rate), 2) : round($order->order_total * $exchange_rate, 2),
                    'user_id'      => $user->id
                ]);
                
                // 支付成功，从用户账户扣除余额抵扣金额
                if ($result['ret'] === 1) {
                    $user->money -= $order->credit_paid;
                    $user->save();
                }
                return $response->withJson($result);
            }
        } catch (\Exception $e) {
            return $response->withJson([
                'ret' => 0,
                    //'msg' => $e->getFile() . $e->getLine() . $e->getMessage(),
                    'msg' => $e->getMessage(),
                ]);
            }

        return $response->withJson([
            'ret' => 2, // 0时表示错误; 1是在线支付订单创建成功状态码; 2分配给账户余额支付
            'msg' => I18n::get()->t('success'),
        ]);
    }

    public static function execute($order_no)
    {
        $order = Order::where('order_no', $order_no)->first();
        
        if (is_null($order->product_id) && $order->order_type === 2) {
            return self::executeAddCredit($order);
        } else {
            return self::executeProduct($order);
        }
    }

    public static function executeAddCredit($order)
    {
        if ($order->execute_status !== 1) {
            $order->paid_time      = time();
            $order->updated_time   = time();
            $order->order_status   = 2;
            $order->execute_status = 1;
            $order->save();

            $user         = User::find($order->user_id);
            $user->money += $order->order_total + $order->bonus_amount;
            $user->save();

            if ($user->ref_by > 0 && Setting::obtain('invitation_mode') === 'after_topup') {
                Payback::rebate($user->id, $order->order_total);
            }
        }
    }

    public static function executeProduct($order)
    {
        $product = Product::find($order->product_id);
        $user    = User::find($order->user_id);

        if ($order->execute_status != '1') {
            $order->order_status = 2;
            $order->updated_time = time();
            $order->paid_time    = time();

            if (!empty($order->coupon_id)) {
                $coupon                   = Coupon::where('id', $order->coupon_id)->first();
                $coupon->use_count       += 1;
                $coupon->discount_amount += $order->product_price - $order->order_total - $order->credit_paid;
                $coupon->save();
            }

            $product->purchase($user, $order);           

            // 返利
            if ($user->ref_by > 0 && Setting::obtain('invitation_mode') === 'after_purchase') {
                Payback::rebate($user->id, $order->order_total);
            }

            // 如果上面的代码执行成功，没有报错，再标记为已处理
            $order->execute_status = 1;
            $order->save();
            
            $product->sales += 1; // 加累积销量     
            $product->save();
        }
    }

    public function verifyCoupon(ServerRequest $request, Response $response, array $args)
    {
        $coupon_code = $request->getParsedBodyParam('coupon_code');
        $product_id = $request->getParsedBodyParam('product_id');
        $product_price = $request->getParsedBodyParam('product_price');
        $user = $this->user;

        if (!$user->isLogin) {
            $res['ret'] = -1;
            return $response->withJson($res);
        }

        $product = Product::where('id', $product_id)->where('status', 1)->first();

        if (is_null($product)) {
            $res['ret'] = 0;
            $res['msg'] = I18n::get()->t('error request');
            return $response->withJson($res);
        }

        $coupons = Coupon::where('code', $coupon_code)->first();

        if (is_null($coupons)) {
            $res['ret'] = 0;
            $res['msg'] = I18n::get()->t('promo code invalid');
            return $response->withJson($res);
        }

        if ($coupons->expire < time()) {
            $res['ret'] = 0;
            $res['msg'] = I18n::get()->t('promo code has expired');
            return $response->withJson($res);
        }
        
        if ($coupons->order($product->id) == false) {
            $res['ret'] = 0;
            $res['msg'] = I18n::get()->t('the conditions of use are not met');
            return $response->withJson($res);
        }

        $per_use_limit = $coupons->per_use_count;
        if ($per_use_limit > 0) {
            $use_count = Order::where('user_id', $user->id)
                ->where('coupon_id', $coupons->id)
                ->where('order_status', 'paid')
                ->count();
            if ($use_count >= $per_use_limit) {
                $res['ret'] = 0;
                $res['msg'] = I18n::get()->t('promo code have been used up');
                return $response->withJson($res);
            }
        }

        $total_use_limit = $coupons->total_use_count;
        if ($total_use_limit > 0) {
            $total_use_count = Order::where('coupon_id', $coupons->id)
                ->where('order_status', 'paid')
                ->count();
            if ($total_use_count >= $total_use_limit) {
                $res['ret'] = 0;
                $res['msg'] = I18n::get()->t('promo code have been used up');
                return $response->withJson($res);
            }
        }

        $res['ret']     = 1;
        $res['total']   = round($product_price * ((100 - $coupons->discount) / 100), 2);
        return $response->withJson($res);
    }

    public static function createOrderNo(){
        $order_id_main = date('YmdHis') . rand(10000000,99999999);
        $order_id_len  = strlen($order_id_main);
        $order_id_sum  = 0;

        for($i=0; $i<$order_id_len; $i++){
            $order_id_sum += (int)(substr($order_id_main,$i,1));
        }

        $order_no = $order_id_main . str_pad((100 - $order_id_sum % 100) % 100,2,'0',STR_PAD_LEFT);
        return $order_no;
    }
}
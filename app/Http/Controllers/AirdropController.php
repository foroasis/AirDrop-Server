<?php


namespace App\Http\Controllers\API;


use App\Models\TwitterUserInfoModel;
use Illuminate\Http\Request;

class AirdropController extends BaseController
{

    /**
     * 空投列表
     * @return \Illuminate\Http\JsonResponse
     */
    public function list()
    {
        $data = TwitterUserInfoModel::query()
            ->where('is_retweeted', 1)
            ->where('is_followers', 1)
            ->where('is_pay', 0)
            ->where('address', '!=', "")
            ->select('tg_user_id', 'address')
            ->get();
        foreach ($data as $key => $value) {
            $data[$key]->amount = 5;
        }
        return $this->success($data);
    }


    /**
     * 回调
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function callback(Request $request)
    {
        $address = $request->get('address');
        $tg_user_id = $request->get('tg_user_id');
        $tx_hash = $request->get('tx_hash');
        $update = [
            'tx_hash' => (string)$tx_hash,
            'is_pay' => 1
        ];
        TwitterUserInfoModel::query()
            ->where('tg_user_id', $tg_user_id)
            ->where('address', $address)
            ->update($update);
        return $this->success([]);
    }

}

<?php

namespace App\Services;


use App\Models\TwitterFollowersModel;
use App\Models\TwitterRetweetedModel;
use App\Models\TwitterUserInfoModel;
use App\Models\UserModel;
use App\Models\UsersWallet;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class TwitterService extends BaseService
{
    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var string
     */
    private $apiKeySecret;

    /**
     * @var string
     */
    private $uri;

    /**
     * @var
     */
    private $twitterArtId;

    /**
     * @var
     */
    private $twitterUserId;

    private $oauth2Token;

    public function __construct()
    {
        $this->uri = config('twitter.api_uri');
        $this->apiKey = config('twitter.api_key');
        $this->apiKeySecret = config('twitter.api_key_secret');
        $this->twitterArtId = config('twitter.twitter_art_id');
        $this->twitterUserId = config('twitter.twitter_user_id');
        $this->getOauth2Token();
    }


    /**
     * 获取token
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getOauth2Token()
    {
        $redis = Redis::connection('user');
        $key = "twitter_oauth2_token";
        $res = $redis->get($key);
        if ($res) {
            $this->oauth2Token = $res;
            return;
        }

        try {
            $url = $this->uri . "oauth2/token";
            $client = new Client();
            $body = ["grant_type" => "client_credentials"];
            $basic = base64_encode("{$this->apiKey}:{$this->apiKeySecret}");
            $res = $client->post($url, [
                'headers' => [
                    "Authorization" => "Basic {$basic}"
                ],
                'form_params' => $body
            ]);
            $c = $res->getBody()->getContents();
            $c = json_decode($c, true);
            $redis->set($key, $c['access_token']);
            $redis->expire($key, 7200);
            $this->oauth2Token = $c['access_token'];
        } catch (\Exception $exception) {
            throw new \Exception("twitter数据获取失败" . $exception->getMessage());
        }
    }


    /**
     * 判断是否领取
     * @param $tgId
     * @return bool
     */
    public function checkIsDrop($tgId)
    {
        return TwitterUserInfoModel::query()
            ->where('tg_user_id', $tgId)
//            ->where('is_pay', $tgId)
            ->first();
    }


    /**
     * 获取推特的转发用户数据
     */
    public function getTwitterRetweeted()
    {
        $url = $this->uri . "2/tweets/{$this->twitterArtId}/retweeted_by";
        $data = $this->getTwitterData($url);
        if (!isset($data['data'])) {
            return;
        }
        $insert = [];
        $userId = [];
        foreach ($data['data'] as $item) {
            $e = TwitterRetweetedModel::query()
                ->where('tweets_id', $this->twitterArtId)
                ->where('user_id', $item['id'])
                ->exists();
            if ($e) {
                continue;
            }
            $userId [] = $item['id'];
            $insert[] = [
                'tweets_id' => $this->twitterArtId,
                'user_id' => $item['id'],
                'username' => $item['username'],
                'nickname' => $item['name'],
                'next_token' => $data['next_token'] ?? '',
                'created_at' => Carbon::now(),
            ];
            if (count($insert) == 10) {
                TwitterRetweetedModel::query()->insert($insert);
                $insert = [];
            }
        }
        if ($insert) {
            TwitterRetweetedModel::query()->insert($insert);
        }

        //更新是否转发
        $sql = "update alphagram_twitter_user_info set is_retweeted = 1 where tweets_id in (select user_id from alphagram_twitter_retweeted) and  is_retweeted = 0";
        DB::update($sql);
    }

    /**
     * 获取推特的转发用户数据
     */
    public function getAllTwitterRetweeted($next_token = "")
    {
        $url = $this->uri . "2/tweets/{$this->twitterArtId}/retweeted_by";
        if ($next_token) {
            $url .= "?pagination_token=" . $next_token;
        }
        $data = $this->getTwitterData($url);
        if (!isset($data['data'])) {
            return;
        }
        $insert = [];
        foreach ($data['data'] as $item) {
            $e = TwitterRetweetedModel::query()
                ->where('tweets_id', $this->twitterArtId)
                ->where('user_id', $item['id'])
                ->exists();
            if ($e) {
                continue;
            }
            $insert[] = [
                'tweets_id' => $this->twitterArtId,
                'user_id' => $item['id'],
                'username' => $item['username'],
                'nickname' => $item['name'],
                'next_token' => $data['next_token'] ?? '',
                'created_at' => Carbon::now(),
            ];
            if (count($insert) == 10) {
                TwitterRetweetedModel::query()->insert($insert);
                $insert = [];
            }
        }
        if ($insert) {
            TwitterRetweetedModel::query()->insert($insert);
        }

        if (isset($data['meta']['next_token'])) {
            $this->getAllTwitterRetweeted($data['meta']['next_token']);
        }
    }


    /**
     * 空投消息发送
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function sendNotice()
    {
        $s = new TelegramService();
        $data = TwitterUserInfoModel::query()
            ->where('is_retweeted', 1)
            ->where('is_followers', 1)
            ->where('is_pay', 0)
            ->where('is_notice', 0)
            ->get();
        foreach ($data as $value) {
            $s->sendMessage("Congratulations! Airdrop rewards will be sent within 12 hours in this message! We will send you a message when the airdrop award is sent to you.", $value->tg_user_id, [], 1);
        }
        TwitterUserInfoModel::query()
            ->where('is_retweeted', 1)
            ->where('is_followers', 1)
            ->where('is_pay', 0)
            ->where('is_notice', 0)
            ->update(['is_notice' => 1]);


        $data = TwitterUserInfoModel::query()
            ->where('is_pay', 1)
            ->where('is_notice', '!=', 2)
            ->get();

        foreach ($data as $value) {
            $s->sendMessage("\$ROSE have been sent to your wallet, please confirm!", $value->tg_user_id, [], 1);
        }
        TwitterUserInfoModel::query()
            ->where('is_pay', 1)
            ->update(['is_notice' => 2]);
    }


    public function syncAddress()
    {
        $data = TwitterUserInfoModel::query()
            ->where('is_retweeted', 1)
            ->where('is_followers', 1)
            ->where('address', "")
            ->get();
        if ($data->isEmpty()) {
            return;
        }
        foreach ($data as $value) {
            $user = UserModel::query()
                ->where('tg_user_id', $value->tg_user_id)
                ->first();

            if (empty($user)) {
                continue;
            }
            $address = UsersWallet::query()
                ->where('user_id', $user->id)
                ->orderBy('updated_at', 'desc')
                ->value('wallet_address');
            if ($address) {
                $value->address = $address;
                $value->save();
            }
        }
    }

    /**
     * 绑定用户
     * @param $username
     * @param $tgUserId
     * @return bool|string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function bind($username, $tgUserId)
    {
        try {
            $url = $this->uri . "2/users/by/username/{$username}";
            $data = $this->getTwitterData($url);
        } catch (\Exception $exception) {
            return "Sorry, this username is not found. Please enter again.";
        }

        if (!isset($data['data']['id'])) {
            return "Sorry, this username is not found. Please enter again.";
        }
        $e = TwitterUserInfoModel::query()->where('tweets_id', $data['data']['id'])->exists();
        if ($e) {
            return "Congratulations! Only a few tasks left to receive the airdrop reward. Remember to follow @alphagramapp and retweet the pinned tweet on Twitter! ";
        }
        $insert = [
            'tweets_id' => $data['data']['id'],
            'username' => $data['data']['username'],
            'nickname' => $data['data']['name'],
            'tg_user_id' => $tgUserId
        ];
        TwitterUserInfoModel::query()->create($insert);
        return true;
    }

    /**
     * 获取关注用户列表
     */
    public function getTwitterFollowers()
    {
        $url = $this->uri . "2/users/{$this->twitterUserId}/followers";
        $data = $this->getTwitterData($url);
        if (!isset($data['data'])) {
            return;
        }
        $insert = [];
        $userId = [];
        foreach ($data['data'] as $item) {
            $e = TwitterFollowersModel::query()->where('user_id', $item['id'])->exists();
            if ($e) {
                continue;
            }
            $insert[] = [
                'user_id' => $item['id'],
                'username' => $item['username'],
                'nickname' => $item['name'],
                'next_token' => $data['meta']['next_token'] ?? '',
                'created_at' => Carbon::now(),
            ];
            $userId[] = $item['id'];
            if (count($insert) == 10) {
                TwitterFollowersModel::query()->insert($insert);
                $insert = [];
            }
        }
        if ($insert) {
            TwitterFollowersModel::query()->insert($insert);
        }

        //更新是否关注
        $sql = "update alphagram_twitter_user_info set is_followers = 1 where tweets_id in (select user_id from alphagram_twitter_followers) and  is_followers = 0";
        DB::update($sql);
    }


    /**
     * 获取推特的关注列表
     * @param string $next_token
     */
    public function getALlTwitterFollowers($next_token = "")
    {
        $url = $this->uri . "2/users/{$this->twitterUserId}/followers";
        if ($next_token) {
            $url .= "?pagination_token=" . $next_token;
        }
        var_dump($url);
        $data = $this->getTwitterData($url);
        if (!isset($data['data'])) {
            return;
        }

        $insert = [];
        foreach ($data['data'] as $item) {
            $e = TwitterFollowersModel::query()->where('user_id', $item['id'])->exists();
            if ($e) {
                continue;
            }
            $insert[] = [
                'user_id' => $item['id'],
                'username' => $item['username'],
                'nickname' => $item['name'],
                'next_token' => $data['meta']['next_token'] ?? '',
                'created_at' => Carbon::now(),
            ];
            if (count($insert) == 10) {
                TwitterFollowersModel::query()->insert($insert);
                $insert = [];
            }
        }
        if ($insert) {
            TwitterFollowersModel::query()->insert($insert);
        }
        if (isset($data['meta']['next_token'])) {
            sleep(10);
            $this->getALlTwitterFollowers($data['meta']['next_token']);
        }
    }


    /**
     * 获取数据
     * @param $url
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getTwitterData($url)
    {
        try {
            $client = new Client();
            $res = $client->get($url, [
                'headers' => [
                    "Authorization" => "Bearer {$this->oauth2Token}"
                ],
            ]);
            $content = $res->getBody()->getContents();
            return json_decode($content, true);

        } catch (\Exception $exception) {
            throw new \Exception($exception->getMessage());
        }
    }
}

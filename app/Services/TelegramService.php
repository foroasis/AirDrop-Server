<?php

namespace App\Services;


use App\Daos\BotGroupDao;
use App\Models\TgGroupUsersModel;
use App\Models\UserModel;
use App\Models\UsersWallet;
use App\Models\Web3\Web3GroupAuditLogModel;
use Telegram\Bot\Laravel\Facades\Telegram;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\TelegramClient;

class TelegramService extends BaseService
{

    /**
     * 处理按钮回调参数
     * @param $callbackQueryData
     * @param $from
     * @param $chatId
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function callbackQuery($callbackQueryData, $from, $chatId)
    {
        $service = new TwitterService();
        if ($callbackQueryData == config('game.bot.callback_query.airdrop_status')) {
            $info = $service->checkIsDrop($from['id']);
            if ($info && $info->is_pay == 1) {
                $message = "Your account has already received a \$ROSE airdrops reward";
                $this->sendMessage($message, $chatId, [], 1);
            } else {
                $this->sendStep($from['id'], $info);
            }
        } else if ($callbackQueryData == config('game.bot.callback_query.twitter_account')) {
            $message = "Twitter username –– also known as your handle –– begins with the “@” symbol. " . PHP_EOL;
            $message .= "Example: @yourusername";
            $this->sendMessage($message, $chatId, [], 1);
        }
    }

    /**
     * 欢迎入群
     * @param $new_chat_member
     * @param $chatId
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function sayHello($new_chat_member, $chatId)
    {
        $message = "Welcome to the alphagram airdrop group🎉 Complete airdrop tasks on 11/28-12/4 to receive airdrop rewards! For more information, please click my profile photo and dm me and send /help to input your Twitter username (handle) first.";
        $this->sendMessage($message, $chatId, [], 1);

        $chatId = config('twitter.twitter_chat_id');
        //加入群
        $insert = [
            'chat_id' => $chatId,
            'user_id' => $new_chat_member['id']
        ];
        TgGroupUsersModel::query()->firstOrCreate($insert);
        //写入redis
        BotGroupDao::setGroupConfig($chatId, $new_chat_member['id']);
    }

    /**
     * 无权限的群
     * @param $chatId
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function sendNoAuthMessage($chatId)
    {
        $message = "请先加入群";
        $this->sendMessage($message, $chatId, [], 1);
    }


    /**
     * 处理绑定数据
     * @param $message
     * @param $chat
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function handleMessage($message, $chat)
    {
        $text = $message->get('text', false);
        $from = $message->get('from', false);
        Log::info("text", [$text]);
        Log::info("from", [$from, $from['id']]);
        if (empty($text) || empty($from)) {
            return;
        }

        if (strpos($text, "/help") !== false) {
            $this->handleHelp($chat->id);
            return;
        }

        if (strpos($text, "@") === false) {
            return;
        }

        //判断chatID
//        if ($chat->id != config('twitter.twitter_chat_id')) {
//            return;
//        }

        $r = explode('@', $text);
        if (!isset($r[1]) || empty($r[1])) {
            Log::info("r_1", [$r]);
            return;
        }
        $s = new TwitterService();
        $res = $s->bind($r[1], $from['id']);
        Log::info("bind_res", [$res]);
        if ($res === true) {
            $message = "Congratulations! Only a few tasks left to receive the airdrop reward. Remember to follow @alphagramapp and retweet the pinned tweet on Twitter! " . PHP_EOL;
            $btnInline = [
                [['text' => "Airdrop status", 'callback_data' => config('game.bot.callback_query.airdrop_status')]],
            ];
            $reply_markup = [
                'inline_keyboard' => $btnInline,
                'resize_keyboard' => true,
                'one_time_keyboard' => false
            ];
            $options = [
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => json_encode($reply_markup)
            ];
            $this->sendMessage($message, $chat->id, $options, 1);
        } else {
            $this->sendMessage($res, $chat->id, [], 1);
        }
    }


    public function handleHelp($chatId)
    {
        $service = new TwitterService();
        $info = $service->checkIsDrop($chatId);
        if (empty($info)) {
            $message = "Please enter your Twitter username to verify the following and retweet behavior.";
            $btnInline = [
                [['text' => "Twitter account", 'callback_data' => config('game.bot.callback_query.twitter_account')]],
            ];
            $reply_markup = [
                'inline_keyboard' => $btnInline,
                'resize_keyboard' => true,
                'one_time_keyboard' => false
            ];
            $options = [
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => json_encode($reply_markup)
            ];
            $this->sendMessage($message, $chatId, $options, 1);
        } else {
            $this->sendStep($chatId, $info);
        }
    }

    /**
     * 发送四步骤的数据
     * @param $chatId
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function sendStep($chatId, $info = [])
    {
        $service = new TwitterService();
        if (empty($info)) {
            $info = $service->checkIsDrop($chatId);
        }
        $step1 = "(Incomplete)";
        $step2 = "(complete)";
        $step3 = "(Incomplete)";
        $step4 = "(Incomplete)";
        if ($info) {
            $user = UserModel::query()->select('id')->where('tg_user_id', $info->tg_user_id)->first();
            if ($user) {
                $step1 = "(complete)";

                $address = UsersWallet::query()->where('user_id', $user->id)->exists();
                if ($address) {
                    $step4 = "(complete)";
                }
            }

            $step3 = ($info->is_followers && $info->is_retweeted) ? " (complete)" : "(Incomplete)";
        }
        $message = "Your account has not yet received a \$ROSE airdrops reward." . PHP_EOL;
        $message .= PHP_EOL;
        $message .= "You have not completed the airdrop tasks. Please complete all the airdrop steps ASAP to receive the airdrop reward." . PHP_EOL;
        $message .= PHP_EOL;
        $message .= "Step 1: Download alphagram  app {$step1}" . PHP_EOL;
        $message .= PHP_EOL;
        $message .= "Step 2: Join the alphagram airdrop campaign group and follow the instructions of alpha baby bot.  {$step2}" . PHP_EOL;
        $message .= PHP_EOL;
        $message .= "Step 3: Follow and retweet the pinned post of the alphagram official Twitter (@alphagramapp) → Note: It takes ten minutes to update the Twitter task status.  {$step3}" . PHP_EOL;
        $message .= PHP_EOL;
        $message .= "[!] Connect your MetaMask wallet on alphagram app: Settings (the last tab) → Connect Wallet → follow the steps till it shows “connected” {$step4}" . PHP_EOL;
        $message .= PHP_EOL;
        $message .= "If you have any questions, please join the alphagram group. We will reply to you ASAP.";

        $btnInline = [
            [['text' => "Download alphagram", 'url' => "https://play.google.com/store/apps/details?id=app.alphagram.messenger"]],
            [['text' => "alphagram Twitter", 'url' => "https://twitter.com/alphagramapp"]],
            [['text' => "alphagram group", 'url' => "https://t.me/alphagramgroup"]],
        ];
        $reply_markup = [
            'inline_keyboard' => $btnInline,
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
        $options = [
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode($reply_markup)
        ];
        $this->sendMessage($message, $chatId, $options, 1);
    }

    // 一些SDK未定义的功能函数
    // 新生成群组邀请链接
    public function newInviteLink($userId, $chatId, $limit = 1, $expire = null)
    {
        $param = [
            'chat_id' => $chatId,
            'member_limit' => $limit
        ];
        if ($expire) {
            $param['expire_date'] = $expire;
        }
        $response = Telegram::post('createChatInviteLink', $param);

        return $response->getResult();
    }


    /**
     * 发送审核消息
     * @param $groupId
     * @param $groupName
     * @param $userId
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function sendGroupAuditMessage($groupId, $groupName, $userId)
    {
        //获取用户的TG_ID
        $user = UserModel::query()->find($userId);
        if (empty($user)) {
            return;
        }

        //发送消息
        $message = "【抱歉，您创建的群组{$groupName}因不符合规范，Alphagram将不再提供群组相关服务。如有疑问请联系 https://twitter.com/alphagramapp 】";
        $res = $this->sendMessage($message, $user->tg_user_id);
        if (isset($res['ok']) && $res['ok'] == true) {
            Log::info("sendGroupAuditMessage_success", [$res]);
            $status = 1;
        } else {
            Log::info("sendGroupAuditMessage_error", [$res]);
            $status = 2;
        }

        $log = [
            'group_id' => $groupId,
            'message' => $message,
            'user_id' => $userId,
            'tg_user_id' => $user->tg_user_id,
            'status' => $status,
            'result' => $res['description'] ?? ''
        ];
        Web3GroupAuditLogModel::query()->create($log);
    }


    /**
     * 发送数据
     * @param $message
     * @param $chatId
     * @return array|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function sendMessage($message, $chatId, $options = [], $token = "")
    {
        try {

            $s = new QueueService();
            return $s->doTgChatMessage($message, $chatId, $options, $token);

//            if (empty($token)) {
//                $token = config('game.bot.token');
//            } else {
//                $token = config('twitter.bot_token');
//            }
//            $url = TelegramClient::BASE_BOT_URL . $token . '/sendMessage';
//            $body = [
//                'chat_id' => $chatId,
//                'text' => $message
//            ];
//            if ($options) {
//                $body += $options;
//            }
//            $client = new \GuzzleHttp\Client();
//            $res = $client->post($url, ['form_params' => $body]);
//            $contents = $res->getBody()->getContents();
//            return json_decode($contents, true);
        } catch (\Exception $exception) {
            return [
                "ok" => false,
                'description' => $exception->getMessage()
            ];
        }
    }
}

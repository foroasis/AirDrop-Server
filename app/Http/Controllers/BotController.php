<?php

namespace App\Http\Controllers;

use App\Daos\BotGroupDao;
use App\Models\Web3\GroupModel;
use App\Services\TelegramService;
use App\Services\TwitterService;
use App\Services\Web3\Web3Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class BotController extends Controller
{

    /**
     * @var Request
     */
    public $request;

    /**
     * AppController constructor.
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }


    //twitter bot callback
    public function alphababy()
    {
        Log::info("start------" . time());
        Log::info("alphababy", $this->request->all());

        try {
            //获取webhook实例
            $update = Telegram::getWebhookUpdate();
            Log::info($update);

            if (!$update) {
                Log::info('NO UPDATE!');
                return 'ok';
            }
            $message = $update->getMessage();
            $chat = $update->getChat();
            Log::info("chat", [$chat]);
            Log::info("message", [$message]);

            $telegramService = new TelegramService();

            //判断是否是新用户
            $new_chat_member = $message->get("new_chat_member", false);
            if ($new_chat_member) {
                if ($chat->id == config('twitter.twitter_chat_id')) {
                    $telegramService->sayHello($new_chat_member, $chat->id);
                }
                return "ok";
            }

            //判断是否是私聊
            if (!isset($chat['type']) || $chat['type'] != 'private') {
                return 'ok';
            }

            //获取来源
            if ($this->request->has('callback_query')) {
                //判断是否是私聊
                $from = $this->request->get('callback_query')['from'];
            } else {
                $from = $message->get('from');
            }
            $chatId = config('twitter.twitter_chat_id');

            //判断chat是否是空
            if (!isset($from['id'])) {
                return 'ok';
            }
            $exists = BotGroupDao::getGroupConfig($chatId, $from['id']);
            if (!$exists) {
                $telegramService->sendNoAuthMessage($chat->id);
                return;
            }

            //判断是否是callback_query
            if ($this->request->has('callback_query')) {
                $data = $this->request->get('callback_query')['data'];
                $from = $this->request->get('callback_query')['from'];
                $telegramService->callbackQuery($data, $from, $chat->id);
                return "ok";
            }

            //判断是否是绑定推特
            $telegramService->handleMessage($message, $chat);

        } catch (\Exception $e) {
            Log::channel('tgWebHookError')->error('-------------------------------------');
            Log::channel('tgWebHookError')->error($e->getMessage());
            Log::channel('tgWebHookError')->error($update ?? '');
            Log::channel('tgWebHookError')->error('-------------------------------------');
            return 'ok';
        }
        return 'ok';
    }

}

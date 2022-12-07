<?php

return [
    "api_key" => env("TWITTER_API_KEY"),
    "api_key_secret" => env("TWITTER_API_KEY_SECRET"),
    'api_uri' => "https://api.twitter.com/",
    'twitter_art_id' => 1596717270419402752,  //默认的推特文章ID 判断是否转发了
    'twitter_user_id' => 1569604967694213122,
    'twitter_chat_id' => env("TWITTER_CHAT_ID", -610489721),//推特官方群

    //bot
    'bot_token' => env('TELEGRAM_TWITTER_BOT_TOKEN'),
    'bot_id' => env('TELEGRAM_TWITTER_BOT_ID'),
    'callback_query' => [
        'airdrop_status' => 'Airdrop_status_callback',
        'twitter_account' => 'Twitter_account_callback',
    ],
];

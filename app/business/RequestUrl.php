<?php

namespace app\business;

class RequestUrl
{
    public static function balance():string
    {
        return '/wallet/balance';
    }
    public static function bet_result():string
    {
        return '/bet_result';
    }
}
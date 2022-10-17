<?php

namespace App\Services;

use Smarty;
use App\Utils;
use Pkly\I18Next\I18n;

class View
{
    public static $connection;
    public static $beginTime;

    public static function getSmarty()
    {
        $smarty = new smarty(); //实例化smarty

        $user = Auth::getUser();

        if ($user->isLogin) {
            $theme = $user->theme;
        } else {
            $theme = $_ENV['theme'];
        }

        $can_backtoadmin = 0;
        if (Utils\Cookie::get('old_uid') && Utils\Cookie::get('old_email') && Utils\Cookie::get('old_key') && Utils\Cookie::get('old_ip') && Utils\Cookie::get('old_expire_in') && Utils\Cookie::get('old_local')) {
            $can_backtoadmin = 1;
        }
        $smarty->settemplatedir(BASE_PATH . '/resources/views/' . $theme . '/'); //设置模板文件存放目录
        $smarty->setcompiledir(BASE_PATH . '/storage/framework/smarty/compile/'); //设置生成文件存放目录
        $smarty->setcachedir(BASE_PATH . '/storage/framework/smarty/cache/'); //设置缓存文件存放目录
        //$smarty->auto_literal = true;
        // add config
        $smarty->assign('config', Config::getPublicConfig());
        $smarty->assign('zeroconfig', ZeroConfig::getPublicSetting());
        $smarty->assign('trans', I18n::get());
        $smarty->assign('user', $user);
        $smarty->assign('can_backtoadmin', $can_backtoadmin);

        if (self::$connection) {
            $smarty->assign('queryLog', self::$connection->connection('default')->getQueryLog());
            $optTime = microtime(true) - self::$beginTime;
            $smarty->assign('optTime', $optTime * 1000);
        }

        return $smarty;
    }
}

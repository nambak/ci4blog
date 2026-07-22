<?php

namespace Config;

use App\Libraries\UploadStorage;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */

    /**
     * 업로드 파일 저장기. 테스트에서 injectMock('uploadStorage', ...) 으로 바꿔 끼운다.
     */
    public static function uploadStorage(bool $getShared = true): UploadStorage
    {
        if ($getShared) {
            return static::getSharedInstance('uploadStorage');
        }

        return new UploadStorage(WRITEPATH . 'uploads');
    }
}

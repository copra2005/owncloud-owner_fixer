<?php
/**
 *
 * @author Semih Serhat Karakaya
 * @copyright Copyright (c) 2016, ITU IT HEAD OFFICE.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\Owner_Fixer;

use OCP\AppFramework\Http\JSONResponse;
use OCP\Util;
class Fixer
{
    /**
     * @var \OCA\Owner_Fixer\Db\DBService $connection
     */
    protected $dbConnection;

    /** @var LdapConnector */
    private static $ldapConnector;

    /** @var nodePermission */
    private static $nodePermission;

    /** @var appPath */
    private static $fixerScriptPath;

    public function __construct($ldapConnector, $dbConnection) {
        self::$ldapConnector = $ldapConnector;
        self::$fixerScriptPath = \OC_App::getAppPath('owner_fixer') . '/lib/owner_fixer';
        self::$nodePermission = \OC::$server->getConfig()->getAppValue('owner_fixer', 'permission_umask');
        $this->dbConnection = $dbConnection;
    }

    /**
     * @param \OCP\Files\Node $node pointing to the file
     * write hook call_back. Check user have enough space to upload.
     */
    public function checkQuota($node) {
        $l = \OC::$server->getL10N('owner_fixer');

        if (!isset($_FILES['files'])) {
            $totalSize = $node->getSize();
        } else {
            $files = $_FILES['files'];
            $totalSize = 0;
            //calculate total uploaded file size
            foreach ($files['size'] as $size) {
                $totalSize += $size;
            }
            $totalSize /= 1024;
        }

        //learn ldap uidnumber
        $ldapUserName = \OC::$server->getUserSession()->getUser()->getUID();

        if(\OC_User::isAdminUser($ldapUserName)) {
            return;
        }

        $ldapUidNumber = $this->learnLdapUidNumber($ldapUserName);
        if($ldapUidNumber === false) {
            \OCP\JSON::error(array('data' => array_merge(array('message' => $l->t('Ldap kullanıcısı değilsiniz. Yükleme yapılamaz.')))));
            die();
        }

        //ask user quota
        $quotaResponse = QuotaManager::getQuotaByUid($ldapUidNumber);

        //if quota manager not responding, return json error and kill all process
        if ($quotaResponse === false) {
            \OCP\JSON::error(array('data' => array_merge(array('message' => $l->t('Kota servisi yanıt vermiyor.')))));
            die();
        }

        //parse result determine quotaLimit and currentUsage
        $quotaLimit = $quotaResponse['quota_limit'];
        $currentUsage = $quotaResponse['current_usage'];

        // TODO: l10n files will be arrange.
        //check have user enough space. if have not set an error message
        if ($currentUsage + $totalSize > $quotaLimit) {
            \OCP\JSON::error(array('data' => array_merge(array('message' => $l->t('Kota limitini aştınız. Yüklediğiniz dosya %s MB boyutunda, fakat %s MB kullanılabilir disk alanınız var', array(round(($totalSize / 1024), 3), round((($quotaLimit - $currentUsage) / 1024), 3)))))));
            die();
        }

    }

    /**
     * @param \OC\Files\Node\File $node
     * @return bool
     */
    public function fixOwnerInRuntime($node) {
        $ldapUserName = $node->getOwner()->getUID();
        $localPath = \OC::$server->getUserFolder($ldapUserName)->getStorage()->getLocalFile($node->getInternalPath());
        if(\OC_User::isAdminUser($ldapUserName)) {
            $this->dbConnection->addNodeToFixedListInRuntime($node->getId());
            return true;
        }
        $ldapUidNumber = $this->learnLdapUidNumber($ldapUserName);
        if($ldapUidNumber === false) {
            Util::writeLog(
                'owner_fixer',
                'learnLdapUidnumber failed to: '. $ldapUserName ,
                Util::ERROR);
            return false;
        }

        //ldap user found. Fix ownership and permissions by using owner_fixer script
        $result = $this->fixOwner($localPath, $ldapUidNumber);
        if($result == 0) {
            $this->dbConnection->addNodeToFixedListInRuntime($node->getId());
        } else {
            Util::writeLog(
                'owner_fixer',
                'owner could not fix. Node Path:'. $localPath ,
                Util::ERROR);
            return false;
        }
        return true;
    }

    /**
     * @param string $fileId
     * @return bool
     */
    public function fixOwnerInCron($fileId) {
        $mountCache = \OC::$server->getMountProviderCollection()->getMountCache();
        $cachedMounts = $mountCache->getMountsForFileId($fileId);
        if (!empty($cachedMounts)) {
            $ldapUserName = $cachedMounts[0]->getUser()->getUID();
            if(\OC_User::isAdminUser($ldapUserName)) {
                $this->dbConnection->updateNodeStatusInFixedList($fileId);
                return true;
            }
            //get internal file path
            $internalNodePath = \OC::$server->getUserFolder($ldapUserName)
                ->getStorage()->getCache()->getPathById($fileId);
            if (empty($internalNodePath)) {
                Util::writeLog(
                    'owner_fixer',
                    'Could not find file with fileid:' . $fileId ,
                    Util::ERROR);
                return false;
            }
            error_log($cachedMounts,1);
            //get local file path
            $nodePath = \OC::$server->getUserFolder($ldapUserName)->getStorage()->getLocalFile($internalNodePath);

            $ldapUidNumber = $this->learnLdapUidNumber($ldapUserName);
            if($ldapUidNumber === false) {
                Util::writeLog(
                    'owner_fixer',
                    'learnLdapUidnumber failed to: '. $ldapUserName .' Node Path:' . $nodePath ,
                    Util::ERROR);
                return false;
            }

            //ldap user found. Fix ownership and permissions by using owner_fixer script
            $result = $this->fixOwner($nodePath, $ldapUidNumber);
            if($result == 0) {
                $this->dbConnection->updateNodeStatusInFixedList($fileId);
            } else {
                Util::writeLog(
                    'owner_fixer',
                    'owner could not fix. Node Path:'. $nodePath ,
                    Util::ERROR);
                return false;
            }
            return true;
        } else {
            $this->dbConnection->deleteFromFixedList($fileId);
        }
    }

    /**
     * @param string $ldapUserName
     * @return bool
     */
    private function learnLdapUidNumber($ldapUserName)
    {
        //search and get uidnumber by using ldapUserName
        $ldapUidNumber = self::$ldapConnector->searchUidNumber($ldapUserName);

        //if it is not an ldap user, don't do anything
        if ($ldapUidNumber == FALSE) {
            return false;
        } else {
            return $ldapUidNumber;
        }
    }

    /**
     * @param string $path
     * @param string $uidNumber
     * @return bool
     */
    private function fixOwner($path, $uidNumber)
    {
        $script = self::$fixerScriptPath . ' "' . $path . '" ' . $uidNumber . " " . self::$nodePermission;
        $output = array();
        exec($script, $output, $returnValue);
        return $returnValue;
    }

}
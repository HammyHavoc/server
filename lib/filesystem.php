<?php

/**
* ownCloud
*
* @author Frank Karlitschek
* @copyright 2012 Frank Karlitschek frank@owncloud.org
*
* This library is free software; you can redistribute it and/or
* modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
* License as published by the Free Software Foundation; either
* version 3 of the License, or any later version.
*
* This library is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU AFFERO GENERAL PUBLIC LICENSE for more details.
*
* You should have received a copy of the GNU Affero General Public
* License along with this library.  If not, see <http://www.gnu.org/licenses/>.
*
*/


/**
 * Class for abstraction of filesystem functions
 * This class won't call any filesystem functions for itself but but will pass them to the correct OC_Filestorage object
 * this class should also handle all the file permission related stuff
 *
 * Hooks provided:
 *   read(path)
 *   write(path, &run)
 *   post_write(path)
 *   create(path, &run) (when a file is created, both create and write will be emited in that order)
 *   post_create(path)
 *   delete(path, &run)
 *   post_delete(path)
 *   rename(oldpath,newpath, &run)
 *   post_rename(oldpath,newpath)
 *   copy(oldpath,newpath, &run) (if the newpath doesn't exists yes, copy, create and write will be emited in that order)
 *   post_rename(oldpath,newpath)
 *
 *   the &run parameter can be set to false to prevent the operation from occuring
 */

class OC_Filesystem{
	static private $storages=array();
	static private $mounts=array();
	static private $loadedUsers=array();
	public static $loaded=false;
	/**
	 * @var OC_Filestorage $defaultInstance
	 */
	static private $defaultInstance;


	/**
	 * classname which used for hooks handling
	 * used as signalclass in OC_Hooks::emit()
	 */
	const CLASSNAME = 'OC_Filesystem';

	/**
	 * signalname emited before file renaming
	 * @param oldpath
	 * @param newpath
	 */
	const signal_rename = 'rename';

	/**
	 * signal emited after file renaming
	 * @param oldpath
	 * @param newpath
	 */
	const signal_post_rename = 'post_rename';

	/**
	 * signal emited before file/dir creation
	 * @param path
	 * @param run changing this flag to false in hook handler will cancel event
	 */
	const signal_create = 'create';

	/**
	 * signal emited after file/dir creation
	 * @param path
	 * @param run changing this flag to false in hook handler will cancel event
	 */
	const signal_post_create = 'post_create';

	/**
	 * signal emits before file/dir copy
	* @param oldpath
	 * @param newpath
	  * @param run changing this flag to false in hook handler will cancel event
	 */
	const signal_copy = 'copy';

	/**
	 * signal emits after file/dir copy
	 * @param oldpath
	 * @param newpath
	 */
	const signal_post_copy = 'post_copy';

	/**
	 * signal emits before file/dir save
	 * @param path
	 * @param run changing this flag to false in hook handler will cancel event
	 */
	const signal_write = 'write';

	/**
	 * signal emits after file/dir save
	 * @param path
	 */
	const signal_post_write = 'post_write';

	/**
	 * signal emits when reading file/dir
	 * @param path
	 */
	const signal_read = 'read';

	/**
	 * signal emits when removing file/dir
	 * @param path
	 */
	const signal_delete = 'delete';

	/**
	 * parameters definitions for signals
	 */
	const signal_param_path = 'path';
	const signal_param_oldpath = 'oldpath';
	const signal_param_newpath = 'newpath';

	/**
	 * run - changing this flag to false in hook handler will cancel event
	 */
	const signal_param_run = 'run';

	/**
	 * get the mountpoint of the storage object for a path
	 ( note: because a storage is not always mounted inside the fakeroot, the returned mountpoint is relative to the absolute root of the filesystem and doesn't take the chroot into account
	 *
	 * @param string path
	  * @return string
	 */
	static public function getMountPoint($path) {
		OC_Hook::emit(self::CLASSNAME,'get_mountpoint', array('path'=>$path));
		if(!$path) {
			$path='/';
		}
		if($path[0]!=='/') {
			$path='/'.$path;
		}
		$path=str_replace('//', '/',$path);
		$foundMountPoint='';
		$mountPoints=array_keys(OC_Filesystem::$mounts);
		foreach($mountPoints as $mountpoint) {
			if($mountpoint==$path) {
				return $mountpoint;
			}
			if(strpos($path,$mountpoint)===0 and strlen($mountpoint)>strlen($foundMountPoint)) {
				$foundMountPoint=$mountpoint;
			}
		}
		return $foundMountPoint;
	}

	/**
	* get the part of the path relative to the mountpoint of the storage it's stored in
	* @param  string  path
	* @return bool
	*/
	static public function getInternalPath($path) {
		$mountPoint=self::getMountPoint($path);
		$internalPath=substr($path, strlen($mountPoint));
		return $internalPath;
	}
	
	static private function mountPointsLoaded($user) {
		return in_array($user, self::$loadedUsers);
	}
	
	/**
	* get the storage object for a path
	* @param string path
	* @return OC_Filestorage
	*/
	static public function getStorage($path) {
		$user = ltrim(substr($path, 0, strpos($path, '/', 1)), '/');
		// check mount points if file was shared from a different user
		if ($user != OC_User::getUser() && !self::mountPointsLoaded($user)) {
			OC_Util::loadUserMountPoints($user);
			self::loadSystemMountPoints($user);
			self::$loadedUsers[] = $user;
		}

		$mountpoint=self::getMountPoint($path);
		if($mountpoint) {
			if(!isset(OC_Filesystem::$storages[$mountpoint])) {
				$mount=OC_Filesystem::$mounts[$mountpoint];
				OC_Filesystem::$storages[$mountpoint]=OC_Filesystem::createStorage($mount['class'],$mount['arguments']);
			}
			return OC_Filesystem::$storages[$mountpoint];
		}
	}

	static private function loadSystemMountPoints($user) {
		if(is_file(OC::$SERVERROOT.'/config/mount.php')) {
			$mountConfig=include OC::$SERVERROOT.'/config/mount.php';
			if(isset($mountConfig['global'])) {
				foreach($mountConfig['global'] as $mountPoint=>$options) {
					self::mount($options['class'],$options['options'],$mountPoint);
				}
			}
		
			if(isset($mountConfig['group'])) {
				foreach($mountConfig['group'] as $group=>$mounts) {
					if(OC_Group::inGroup($user,$group)) {
						foreach($mounts as $mountPoint=>$options) {
							$mountPoint=self::setUserVars($mountPoint, $user);
							foreach($options as &$option) {
								$option=self::setUserVars($option, $user);
							}
							self::mount($options['class'],$options['options'],$mountPoint);
						}
					}
				}
			}
		
			if(isset($mountConfig['user'])) {
				foreach($mountConfig['user'] as $user=>$mounts) {
					if($user==='all' or strtolower($user)===strtolower($user)) {
						foreach($mounts as $mountPoint=>$options) {
							$mountPoint=self::setUserVars($mountPoint, $user);
							foreach($options as &$option) {
								$option=self::setUserVars($option, $user);
							}
							self::mount($options['class'],$options['options'],$mountPoint);
						}
					}
				}
			}
		
			$mtime=filemtime(OC::$SERVERROOT.'/config/mount.php');
			$previousMTime=OC_Appconfig::getValue('files','mountconfigmtime',0);
			if($mtime>$previousMTime) {//mount config has changed, filecache needs to be updated
				OC_FileCache::triggerUpdate();
				OC_Appconfig::setValue('files','mountconfigmtime',$mtime);
			}
		}		
	}
	
	static public function init($root, $user = '') {
		if(self::$defaultInstance) {
			return false;
		}
		self::$defaultInstance=new OC_FilesystemView($root);

		//load custom mount config
		if (!isset($user)) {
			$user = OC_User::getUser();
		}
		self::loadSystemMountPoints($user);

		self::$loaded=true;
	}

	/**
	 * fill in the correct values for $user, and $password placeholders
	 * @param string intput
	 * @return string
	 */
	private static function setUserVars($input, $user) {
		if (isset($user)) {
			return str_replace('$user', $user,$input);
		} else {
			return str_replace('$user',OC_User::getUser(),$input);
		}
	}

	/**
	 * get the default filesystem view
	 * @return OC_FilesystemView
	 */
	static public function getView() {
		return self::$defaultInstance;
	}

	/**
	 * tear down the filesystem, removing all storage providers
	 */
	static public function tearDown() {
		self::$storages=array();
	}

	/**
	* create a new storage of a specific type
	* @param  string  type
	* @param  array  arguments
	* @return OC_Filestorage
	*/
	static private function createStorage($class,$arguments) {
		if(class_exists($class)) {
			try {
				return new $class($arguments);
			} catch (Exception $exception) {
				OC_Log::write('core', $exception->getMessage(), OC_Log::ERROR);
				return false;
			}
		}else{
			OC_Log::write('core','storage backend '.$class.' not found',OC_Log::ERROR);
			return false;
		}
	}

	/**
	* change the root to a fake root
	* @param  string  fakeRoot
	* @return bool
	*/
	static public function chroot($fakeRoot) {
		return self::$defaultInstance->chroot($fakeRoot);
	}

	/**
	 * @brief get the relative path of the root data directory for the current user
	 * @return string
	 *
	 * Returns path like /admin/files
	 */
	static public function getRoot() {
		return self::$defaultInstance->getRoot();
	}

	/**
	 * clear all mounts and storage backends
	 */
	public static function clearMounts() {
		self::$mounts=array();
		self::$storages=array();
	}

	/**
	* mount an OC_Filestorage in our virtual filesystem
	* @param OC_Filestorage storage
	* @param string mountpoint
	*/
	static public function mount($class,$arguments,$mountpoint) {
		if($mountpoint[0]!='/') {
			$mountpoint='/'.$mountpoint;
		}
		if(substr($mountpoint,-1)!=='/') {
			$mountpoint=$mountpoint.'/';
		}
		self::$mounts[$mountpoint]=array('class'=>$class,'arguments'=>$arguments);
	}

	/**
	* return the path to a local version of the file
	* we need this because we can't know if a file is stored local or not from outside the filestorage and for some purposes a local file is needed
	* @param string path
	* @return string
	*/
	static public function getLocalFile($path) {
		return self::$defaultInstance->getLocalFile($path);
	}
	/**
	 * @param string path
	 * @return string
	 */
	static public function getLocalFolder($path) {
		return self::$defaultInstance->getLocalFolder($path);
	}

	/**
	* return path to file which reflects one visible in browser
	* @param string path
	* @return string
	*/
	static public function getLocalPath($path) {
		$datadir = OC_User::getHome(OC_User::getUser()).'/files';
		$newpath = $path;
		if (strncmp($newpath, $datadir, strlen($datadir)) == 0) {
			$newpath = substr($path, strlen($datadir));
		}
		return $newpath;
	}

	/**
	 * check if the requested path is valid
	 * @param string path
	 * @return bool
	 */
	static public function isValidPath($path) {
		if(!$path || $path[0]!=='/') {
			$path='/'.$path;
		}
		if(strstr($path,'/../') || strrchr($path, '/') === '/..' ) {
			return false;
		}
		return true;
	}

	/**
	 * checks if a file is blacklsited for storage in the filesystem
	 * Listens to write and rename hooks
	 * @param array $data from hook
	 */
	static public function isBlacklisted($data) {
		$blacklist = array('.htaccess');
		if (isset($data['path'])) {
			$path = $data['path'];
		} else if (isset($data['newpath'])) {
			$path = $data['newpath'];
		}
		if (isset($path)) {
			$filename = strtolower(basename($path));
			if (in_array($filename, $blacklist)) {
				$data['run'] = false;
			}
		}
	}

	/**
	 * following functions are equivilent to their php buildin equivilents for arguments/return values.
	 */
	static public function mkdir($path) {
		return self::$defaultInstance->mkdir($path);
	}
	static public function rmdir($path) {
		return self::$defaultInstance->rmdir($path);
	}
	static public function opendir($path) {
		return self::$defaultInstance->opendir($path);
	}
	static public function readdir($path) {
		return self::$defaultInstance->readdir($path);
	}
	static public function is_dir($path) {
		return self::$defaultInstance->is_dir($path);
	}
	static public function is_file($path) {
		return self::$defaultInstance->is_file($path);
	}
	static public function stat($path) {
		return self::$defaultInstance->stat($path);
	}
	static public function filetype($path) {
		return self::$defaultInstance->filetype($path);
	}
	static public function filesize($path) {
		return self::$defaultInstance->filesize($path);
	}
	static public function readfile($path) {
		return self::$defaultInstance->readfile($path);
	}
	/**
	* @deprecated Replaced by isReadable() as part of CRUDS
	*/
	static public function is_readable($path) {
		return self::$defaultInstance->is_readable($path);
	}
	/**
	* @deprecated Replaced by isCreatable(), isUpdatable(), isDeletable() as part of CRUDS
	*/
	static public function is_writable($path) {
		return self::$defaultInstance->is_writable($path);
	}
	static public function isCreatable($path) {
		return self::$defaultInstance->isCreatable($path);
	}
	static public function isReadable($path) {
		return self::$defaultInstance->isReadable($path);
	}
	static public function isUpdatable($path) {
		return self::$defaultInstance->isUpdatable($path);
	}
	static public function isDeletable($path) {
		return self::$defaultInstance->isDeletable($path);
	}
	static public function isSharable($path) {
		return self::$defaultInstance->isSharable($path);
	}
	static public function file_exists($path) {
		return self::$defaultInstance->file_exists($path);
	}
	static public function filectime($path) {
		return self::$defaultInstance->filectime($path);
	}
	static public function filemtime($path) {
		return self::$defaultInstance->filemtime($path);
	}
	static public function touch($path, $mtime=null) {
		return self::$defaultInstance->touch($path, $mtime);
	}
	static public function file_get_contents($path) {
		return self::$defaultInstance->file_get_contents($path);
	}
	static public function file_put_contents($path,$data) {
		return self::$defaultInstance->file_put_contents($path,$data);
	}
	static public function unlink($path) {
		return self::$defaultInstance->unlink($path);
	}
	static public function rename($path1,$path2) {
		return self::$defaultInstance->rename($path1,$path2);
	}
	static public function copy($path1,$path2) {
		return self::$defaultInstance->copy($path1,$path2);
	}
	static public function fopen($path,$mode) {
		return self::$defaultInstance->fopen($path,$mode);
	}
	static public function toTmpFile($path) {
		return self::$defaultInstance->toTmpFile($path);
	}
	static public function fromTmpFile($tmpFile,$path) {
		return self::$defaultInstance->fromTmpFile($tmpFile,$path);
	}

	static public function getMimeType($path) {
		return self::$defaultInstance->getMimeType($path);
	}
	static public function hash($type,$path, $raw = false) {
		return self::$defaultInstance->hash($type,$path, $raw);
	}

	static public function free_space($path='/') {
		return self::$defaultInstance->free_space($path);
	}

	static public function search($query) {
		return OC_FileCache::search($query);
	}

	/**
	 * check if a file or folder has been updated since $time
	 * @param int $time
	 * @return bool
	 */
	static public function hasUpdated($path,$time) {
		return self::$defaultInstance->hasUpdated($path,$time);
	}

	static public function removeETagHook($params, $root = false) {
		if (isset($params['path'])) {
			$path=$params['path'];
		} else {
			$path=$params['oldpath'];
		}

		if ($root) { // reduce path to the required part of it (no 'username/files')
			$fakeRootView = new OC_FilesystemView($root);
			$count = 1;
			$path=str_replace(OC_App::getStorage("files")->getAbsolutePath(), "", $fakeRootView->getAbsolutePath($path), $count);
		}

		$path = self::normalizePath($path);
		OC_Connector_Sabre_Node::removeETagPropertyForPath($path);
	}

	/**
	 * normalize a path
	 * @param string path
	 * @param bool $stripTrailingSlash
	 * @return string
	 */
	public static function normalizePath($path,$stripTrailingSlash=true) {
		if($path=='') {
			return '/';
		}
		//no windows style slashes
		$path=str_replace('\\','/',$path);
		//add leading slash
		if($path[0]!=='/') {
			$path='/'.$path;
		}
		//remove trainling slash
		if($stripTrailingSlash and strlen($path)>1 and substr($path,-1,1)==='/') {
			$path=substr($path,0,-1);
		}
		//remove duplicate slashes
		while(strpos($path,'//')!==false) {
			$path=str_replace('//','/',$path);
		}
		//normalize unicode if possible
		if(class_exists('Normalizer')) {
			$path=Normalizer::normalize($path);
		}
		return $path;
	}
}
OC_Hook::connect('OC_Filesystem','post_write', 'OC_Filesystem','removeETagHook');
OC_Hook::connect('OC_Filesystem','post_delete','OC_Filesystem','removeETagHook');
OC_Hook::connect('OC_Filesystem','post_rename','OC_Filesystem','removeETagHook');

OC_Util::setupFS();
require_once 'filecache.php';

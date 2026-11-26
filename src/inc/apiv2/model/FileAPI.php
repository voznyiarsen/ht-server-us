<?php

namespace Hashtopolis\inc\apiv2\model;

use Hashtopolis\dba\AbstractModel;
use Hashtopolis\inc\defines\DDirectories;
use Hashtopolis\inc\utils\AccessUtils;
use Hashtopolis\inc\defines\DFileType;
use Exception;
use Hashtopolis\inc\utils\FileUtils;
use Hashtopolis\dba\models\AccessGroup;
use Hashtopolis\dba\ContainFilter;
use Hashtopolis\dba\Factory;
use Hashtopolis\dba\QueryFilter;
use Hashtopolis\dba\OrderFilter;

use Hashtopolis\dba\models\File;
use Hashtopolis\dba\models\User;
use Hashtopolis\inc\apiv2\common\AbstractModelAPI;
use Hashtopolis\inc\apiv2\error\HttpError;
use Hashtopolis\inc\HTException;
use Hashtopolis\inc\Util;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;


/**
 * @extends AbstractModelAPI<File>
 */
class FileAPI extends AbstractModelAPI {
  public static function getBaseUri(): string {
    return "/api/v2/ui/files";
  }
  
  public static function getDBAclass(): string {
    return File::class;
  }
  
  /**
   * @param File $object
   * @throws Exception
   */
  protected function getSingleACL(User $user, AbstractModel $object): bool {
    $accessGroupsUser = Util::arrayOfIds(AccessUtils::getAccessGroupsOfUser($user));
    
    return in_array($object->getAccessGroupId(), $accessGroupsUser);
  }
  
  /**
   * @throws Exception
   */
  protected function getFilterACL(): array {
    $accessGroups = Util::arrayOfIds(AccessUtils::getAccessGroupsOfUser($this->getCurrentUser()));
    
    return [
      Factory::FILTER => [
        new ContainFilter(File::ACCESS_GROUP_ID, $accessGroups),
      ]
    ];
  }
  
  public static function getToOneRelationships(): array {
    return [
      'accessGroup' => [
        'key' => File::ACCESS_GROUP_ID,
        
        'relationType' => AccessGroup::class,
        'relationKey' => AccessGroup::ACCESS_GROUP_ID,
      ],
    ];
  }
  
  public function getFormFields(): array {
    // TODO Form declarations in more generic class to allow auto-generated OpenAPI specifications
    return [
      "sourceType" => ['type' => 'str'],
      "sourceData" => ['type' => 'str']
    ];
  }
  
  /**
   * @throws Exception
   */
  static protected function getImportPath(): string {
    return Factory::getStoredValueFactory()->get(DDirectories::IMPORT)->getVal() . '/';
  }
  
  /**
   * @throws Exception
   */
  static protected function getFilesPath(): string {
    return Factory::getStoredValueFactory()->get(DDirectories::FILES)->getVal() . '/';
  }
  
  /* Includes:
   * Experimental support for renaming import file to target file
   */
  /**
   * @throws HTException
   * @throws HttpError
   * @throws Exception
   */
  protected function createObject(array $data): int {
    /* Validate target filename */
    $realname = str_replace(" ", "_", htmlentities(basename($data[File::FILENAME]), ENT_QUOTES, "UTF-8"));
    if ($data[File::FILENAME] != $realname) {
      throw new HttpError(File::FILENAME . " is invalid filename suggestion '$realname'");
    }
    
    /* Pre-checking to allow saving some time in repairing edge cases */
    if (file_exists($this->getFilesPath() . $data[File::FILENAME])) {
      throw new HttpError("File '" . $data[File::FILENAME] . "' already exists in 'files' folder, cannot continue!");
    }
    
    /* Prepare dummy request for insert */
    $dummyPost = [
      "filename" => $data[File::FILENAME],
      "accessGroupId" => $data[File::ACCESS_GROUP_ID],
    ];
    switch ($data["sourceType"]) {
      case "inline":
        // TODO: Should be validated as parameter input instead
        /* Write base64 to a temp file first, then stream-decode to disk via
         * Util::uploadFile(base64_file). This avoids holding the entire decoded
         * binary in RAM, which matters for large wordlists on low-RAM hosts. */
        $b64Temp = $this->getFilesPath() . $realname . '.b64';
        if (!file_put_contents($b64Temp, $data["sourceData"])) {
          throw new HttpError("Failed to write base64 data to temporary file!");
        }
        $dummyPost["_uploadType"] = "base64_file";
        $dummyPost["data"] = $b64Temp;
        break;
      case "import":
        $realname = str_replace(" ", "_", htmlentities(basename($data["sourceData"]), ENT_QUOTES, "UTF-8"));
        if ($data["sourceData"] != $realname) {
          throw new HttpError("sourceData is invalid filename suggestion '$realname'");
        }
        /* Renaming files will require target file to be checked before renaming */
        if (!file_exists($this->getImportPath() . $data["sourceData"])) {
          throw new HttpError("File '" . $data["sourceData"] . "' not found in import folder");
        }
        /* We are renaming sourceData file to filename file, check if filename is not there already
           this can be skipped if they are the same */
        if (file_exists($this->getImportPath() . $data[File::FILENAME]) && $data[File::FILENAME] != $data["sourceData"]) {
          throw new HttpError("File required temporary file '" . $data[File::FILENAME] . "' exists import folder, cannot continue");
        }
        /* Since we are renaming the file _before_ import the name is temporary changed */
        $dummyPost["imfile"] = [$data[File::FILENAME]];
        break;
      case "url":
        $dummyPost["url"] = $data["sourceData"];
        break;
      default:
        // TODO: Choice validation are model based checks
        throw new HttpError("sourceType value '" . $data["sourceType"] . "' is not supported (choices inline, import, url");
    }
    
    /* TODO: Hackish view to revert back to required (hardcoded) view */
    $view = [
              DFileType::OTHER => 'other',
              DFileType::RULE => 'rule',
              DFileType::WORDLIST => 'dict'
            ][$data[File::FILE_TYPE]];
    
    
    /* Prepare renaming file if required */
    $doRenameImport = (($data["sourceType"] == "import") && ($data[File::FILENAME] != $data["sourceData"]));
    if ($doRenameImport) {
      rename(
        $this->getImportPath() . $data["sourceData"],
        $this->getImportPath() . $data[File::FILENAME]
      );
    }
    
    try {
      /* Create the file, calculating (e.g. lines) and checking validity (e.g. file exists) */
      FileUtils::add($data["sourceType"], $data[File::FILENAME], $dummyPost, $view);
    }
    catch (Exception $e) {
      /* In case of errors, ensure old state is restored */
      if ($doRenameImport) {
        rename(
          $this->getImportPath() . $data[File::FILENAME],
          $this->getImportPath() . $data["sourceData"]
        );
      }
      throw $e;
    }
    
    /* Hackish way to retrieve object since Id is not returned on creation */
    $qFs = [
      new QueryFilter(File::FILENAME, $data[File::FILENAME], '='),
      new QueryFilter(File::FILE_TYPE, $data[File::FILE_TYPE], '='),
      new QueryFilter(File::ACCESS_GROUP_ID, $data[File::ACCESS_GROUP_ID], '=')
    ];
    $oF = new OrderFilter(File::FILE_ID, "DESC");
    $objects = $this->getFactory()->filter([Factory::FILTER => $qFs, Factory::ORDER => $oF]);
    assert(count($objects) == 1);
    
    /* Manually set secret, since it not set when adding file */
    FileUtils::switchSecret($objects[0]->getId(), ($data[File::IS_SECRET]) ? 1 : 0, $this->getCurrentUser());
    
    /* On successfully insert, return ID */
    return $objects[0]->getId();
  }
  
  protected function getUpdateHandlers($id, $current_user): array {
    return [
      File::FILE_TYPE => fn($value) => FileUtils::setFileType($id, $value, $current_user)
    ];
  }
  
  /**
   * @param File $object
   * @throws HTException
   */
  protected function deleteObject(AbstractModel $object): void {
    FileUtils::delete($object->getId(), $this->getCurrentUser());
  }

  /**
   * Upload a file via multipart/form-data.
   *
   * PHP's SAPI writes the uploaded file directly to a temp file on disk
   * (upload_tmp_dir). We then move_uploaded_file() it to its final
   * destination, so the file contents are never held in application RAM.
   * This supports huge wordlists (e.g. 30+ GB) on extremely low-RAM hosts.
   *
   * Form fields: filename (optional), accessGroupId (required), fileType
   * (optional, default OTHER), isSecret (optional).
   * File field: upfile
   *
   * @param Request $request
   * @param Response $response
   * @param array $args
   * @return Response
   * @throws HttpError
   * @throws Exception
   */
  public function processUpload(Request $request, Response $response, array $args): Response {
    $this->preCommon($request);

    $post = $request->getParsedBody() ?? [];

    if (!isset($_FILES['upfile']) || !is_array($_FILES['upfile']) ||
      $_FILES['upfile']['error'] !== UPLOAD_ERR_OK) {
      $err = isset($_FILES['upfile']['error']) ? $_FILES['upfile']['error'] : -1;
      throw new HttpError("No file uploaded or upload error (code $err)", 400);
    }

    $accessGroupId = $post['accessGroupId'] ?? null;
    if ($accessGroupId === null) {
      throw new HttpError("accessGroupId is required", 400);
    }

    $fileType = intval($post['fileType'] ?? DFileType::OTHER);
    $isSecret = !empty($post['isSecret']);

    $view = [
      DFileType::OTHER => 'other',
      DFileType::RULE => 'rule',
      DFileType::WORDLIST => 'dict'
    ][$fileType] ?? 'other';

    /* Normalize $_FILES so that single-file uploads (string values) are
     * presented as arrays, matching what FileUtils::add("upload", ...)
     * expects. PHP's SAPI already wrote the file to a temp file on disk;
     * we only restructure the metadata array, never the file contents. */
    $files = $_FILES;
    foreach ($files as $field => $fileData) {
      if (isset($fileData['name']) && !is_array($fileData['name'])) {
        $files[$field] = [
          'name' => [$fileData['name']],
          'type' => [$fileData['type'] ?? ''],
          'tmp_name' => [$fileData['tmp_name']],
          'error' => [$fileData['error']],
          'size' => [$fileData['size'] ?? 0],
        ];
      }
    }

    /* FileUtils::add("upload", ...) uses move_uploaded_file, which moves
     * the PHP SAPI temp file to the final destination. The file contents
     * are never read into application RAM. */
    $fileCount = FileUtils::add("upload", $files, [
      "accessGroupId" => $accessGroupId,
    ], $view);

    if ($fileCount === 0) {
      throw new HttpError("File upload failed", 400);
    }

    /* Determine the stored filename from the uploaded file's original name */
    $uploaded = $files['upfile'];
    $realname = str_replace(" ", "_", htmlentities(basename($uploaded["name"][0]), ENT_QUOTES, "UTF-8"));

    /* Look up the created file record */
    $qFs = [
      new QueryFilter(File::FILENAME, $realname, '='),
      new QueryFilter(File::ACCESS_GROUP_ID, $accessGroupId, '=')
    ];
    $oF = new OrderFilter(File::FILE_ID, "DESC");
    $objects = $this->getFactory()->filter([Factory::FILTER => $qFs, Factory::ORDER => $oF]);

    if (count($objects) == 0) {
      throw new HttpError("Failed to retrieve uploaded file", 500);
    }

    if ($isSecret) {
      FileUtils::switchSecret($objects[0]->getId(), 1, $this->getCurrentUser());
    }

    $response->getBody()->write(json_encode([
      "id" => $objects[0]->getId(),
      "filename" => $realname,
    ]));

    return $response->withStatus(201)
      ->withHeader("Content-Type", "application/json")
      ->withHeader("Access-Control-Expose-Headers", "Content-Type");
  }

  /**
   * Register routes including the multipart upload endpoint.
   */
  static public function register(App $app): void {
    $me = static::class;
    parent::register($app);

    $baseUri = $me::getBaseUri();

    $app->group($baseUri, function (RouteCollectorProxy $group) use ($me) {
      $group->post('/upload', [$me, 'processUpload'])
        ->setName($me . ':processUpload');
      $group->options('/upload', function (Request $request, Response $response): Response {
        return $response->withStatus(204);
      });
    });
  }
}


<?php

declare(strict_types=1);

namespace Drupal\Tests\blogapi\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\comment\Tests\CommentTestTrait;
use Drupal\file\FileInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises the Blog API over the /xmlrpc endpoint.
 *
 * Calls go through xmlrpc's own HTTP client, as in xmlrpc's
 * XmlRpcBasicTest, so the whole request path (routing, xmlrpc server,
 * provider callbacks, BlogapiCommunicator) is covered.
 *
 * @group blogapi
 */
#[Group('blogapi')]
#[RunTestsInSeparateProcesses]
class BlogapiXmlRpcTest extends BrowserTestBase {

  use CommentTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * The module's config schema covers only the fixed keys of
   * blogapi.settings; the per-content-type keys are not described yet.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   *
   * The Blog API code uses comment field definitions and file entities
   * without declaring a dependency on comment or file, so both are enabled
   * here explicitly.
   */
  protected static $modules = [
    'xmlrpc',
    'node',
    'taxonomy',
    'comment',
    'file',
    'filter',
    'blogapi',
    'blogapi_blogger',
    'blogapi_metaweblog',
    'blogapi_movabletype',
  ];

  /**
   * The password of every account created by this test.
   */
  protected const PASSWORD = 'correct horse battery staple';

  /**
   * An active account allowed to post blog content over the Blog API.
   */
  protected UserInterface $blogger;

  /**
   * A blocked account with the same permissions and a known password.
   */
  protected UserInterface $blockedBlogger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalCreateContentType(['type' => 'blog', 'name' => 'Blog']);
    $this->addDefaultCommentField('node', 'blog');

    $this->config('blogapi.settings')
      ->set('content_types', ['blog' => 'blog'])
      ->set('text_format', '')
      ->set('body_blog', 'body')
      ->set('comment_blog', 'comment')
      ->save();

    $permissions = [
      'manage own content blogapi',
      'create blog content',
      'edit own blog content',
    ];
    $this->blogger = $this->drupalCreateUser($permissions, 'blogger');
    $this->blogger->setPassword(static::PASSWORD)->save();

    $this->blockedBlogger = $this->drupalCreateUser($permissions, 'blocked_blogger');
    $this->blockedBlogger->setPassword(static::PASSWORD)->block()->save();
  }

  /**
   * Calls a single XML-RPC method on the site under test.
   *
   * @param string $method
   *   The XML-RPC method name.
   * @param array $params
   *   The positional method parameters.
   *
   * @return mixed
   *   The decoded result, or FALSE on a fault; see xmlrpc_errno().
   */
  protected function xmlRpcCall(string $method, array $params) {
    $url = Url::fromRoute('xmlrpc', [], ['absolute' => TRUE])->toString();
    return xmlrpc($url, [$method => $params]);
  }

  /**
   * Valid credentials list the blog types the account may post to.
   */
  public function testGetUsersBlogsWithValidCredentials(): void {
    $result = $this->xmlRpcCall('blogger.getUsersBlogs', ['', 'blogger', static::PASSWORD]);

    $this->assertIsArray($result, (string) xmlrpc_error_msg());
    $this->assertCount(1, $result);
    $this->assertSame('blog', $result[0]['blogid']);
  }

  /**
   * Valid credentials create a node owned by the caller.
   *
   * Positive control for the "no node created" assertion on rejected
   * credentials.
   */
  public function testNewPostWithValidCredentials(): void {
    $post = [
      'title' => 'Created over XML-RPC',
      'description' => '<p>Body</p>',
    ];
    $nid = $this->xmlRpcCall('metaWeblog.newPost', ['blog', 'blogger', static::PASSWORD, $post, TRUE]);
    $this->assertIsString($nid, (string) xmlrpc_error_msg());

    $node = $this->container->get('entity_type.manager')->getStorage('node')->load($nid);
    $this->assertNotNull($node);
    $this->assertSame('blog', $node->bundle());
    $this->assertSame((int) $this->blogger->id(), (int) $node->getOwnerId());
  }

  /**
   * Rejected credentials return the same 401 fault and never write content.
   *
   * A blocked account must be indistinguishable from a wrong password, so
   * the API does not reveal which usernames exist or are blocked.
   *
   * @dataProvider providerRejectedCredentials
   */
  #[DataProvider('providerRejectedCredentials')]
  public function testRejectedCredentials(string $username, string $password): void {
    $result = $this->xmlRpcCall('blogger.getUsersBlogs', ['', $username, $password]);
    $this->assertFalse($result);
    $this->assertSame(401, xmlrpc_errno(), (string) xmlrpc_error_msg());

    $post = [
      'title' => 'Must not be created',
      'description' => '<p>Body</p>',
    ];
    $result = $this->xmlRpcCall('metaWeblog.newPost', ['blog', $username, $password, $post, TRUE]);
    $this->assertFalse($result);
    $this->assertSame(401, xmlrpc_errno(), (string) xmlrpc_error_msg());

    $nids = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    $this->assertSame([], $nids, 'No node was created.');
  }

  /**
   * Data provider for ::testRejectedCredentials().
   *
   * @return array
   *   Test cases: username, password.
   */
  public static function providerRejectedCredentials(): array {
    return [
      'wrong password' => ['blogger', 'wrong password'],
      'unknown username' => ['nobody', static::PASSWORD],
      'blocked account, correct password' => ['blocked_blogger', static::PASSWORD],
    ];
  }

  /**
   * Uploads a file with metaWeblog.newMediaObject as the blogger account.
   *
   * @param string $name
   *   The file name the client sends.
   * @param string $bits
   *   The raw file contents.
   *
   * @return mixed
   *   The decoded result, or FALSE on a fault; see xmlrpc_errno().
   */
  protected function newMediaObject(string $name, string $bits) {
    $this->container->get('module_handler')->loadInclude('xmlrpc', 'inc');
    $media = [
      'name' => $name,
      'type' => 'application/octet-stream',
      'bits' => xmlrpc_base64($bits),
    ];
    return $this->xmlRpcCall('metaWeblog.newMediaObject', ['blog', 'blogger', static::PASSWORD, $media]);
  }

  /**
   * Returns the contents of a core test image.
   */
  protected function imageBytes(string $fixture = 'image-test.png'): string {
    return file_get_contents($this->root . '/core/tests/fixtures/files/' . $fixture);
  }

  /**
   * Loads every file entity, keyed by ID.
   *
   * @return \Drupal\file\FileInterface[]
   *   The file entities.
   */
  protected function loadFiles(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('file');
    $storage->resetCache();
    return $storage->loadMultiple();
  }

  /**
   * Returns the URI an upload stored at a given time should land under.
   *
   * The month is taken in the site's default time zone, whatever the
   * uploader's own time zone is.
   *
   * @param \Drupal\file\FileInterface $file
   *   The stored file; its creation time is the upload's request time.
   * @param string $prefix
   *   The base directory, including its trailing separator.
   */
  protected function expectedDirectory(FileInterface $file, string $prefix = 'public://olw/'): string {
    $timezone = $this->config('system.date')->get('timezone.default');
    $month = $this->container->get('date.formatter')->format($file->getCreatedTime(), 'custom', 'Y-m', $timezone);
    return $prefix . $month;
  }

  /**
   * Lists the regular files in the site's temporary directory.
   *
   * @return string[]
   *   File names.
   */
  protected function temporaryFiles(): array {
    $directory = $this->container->get('file_system')->getTempDirectory();
    return array_values(array_filter(scandir($directory) ?: [], fn ($name) => is_file($directory . '/' . $name)));
  }

  /**
   * An upload lands in a dated subfolder, owned by the uploader.
   *
   * The response carries only the absolute URL, and that URL serves the
   * uploaded bytes. The uploader's time zone is far from the site's, so the
   * month folder would differ around a month boundary if it were used.
   */
  public function testNewMediaObjectSavesToDatedFolder(): void {
    $this->assertSame('Australia/Sydney', $this->config('system.date')->get('timezone.default'));
    $this->blogger->set('timezone', 'Pacific/Pago_Pago')->save();

    $bytes = $this->imageBytes();
    $result = $this->newMediaObject('image_thumb[20]_6.png', $bytes);
    $this->assertIsArray($result, (string) xmlrpc_error_msg());
    $this->assertSame(['url'], array_keys($result));

    $files = $this->loadFiles();
    $this->assertCount(1, $files);
    $file = reset($files);
    $this->assertSame($this->expectedDirectory($file) . '/image_thumb[20]_6.png', $file->getFileUri());
    $this->assertSame((int) $this->blogger->id(), (int) $file->getOwnerId());
    $this->assertTrue($file->isPermanent());
    $this->assertSame(strlen($bytes), (int) $file->getSize());
    $this->assertSame('image/png', $file->getMimeType());

    $expected_url = $this->container->get('file_url_generator')->generateAbsoluteString($file->getFileUri());
    $this->assertSame($expected_url, $result['url']);
    $response = $this->getHttpClient()->get($result['url']);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame($bytes, (string) $response->getBody());
  }

  /**
   * A client-supplied path is reduced to its basename.
   *
   * Open Live Writer may send names like "Open-Live-Writer/<slug>/image.png";
   * those must neither fail nor create client-controlled subdirectories.
   *
   * @dataProvider providerNestedNames
   */
  #[DataProvider('providerNestedNames')]
  public function testNewMediaObjectUsesBasenameOfNestedName(string $name): void {
    $temporary_before = $this->temporaryFiles();

    $result = $this->newMediaObject($name, $this->imageBytes());
    $this->assertIsArray($result, (string) xmlrpc_error_msg());

    $files = $this->loadFiles();
    $this->assertCount(1, $files);
    $file = reset($files);
    $this->assertSame($this->expectedDirectory($file) . '/image.png', $file->getFileUri());
    $this->assertFileExists($file->getFileUri());
    $this->assertSame($temporary_before, $this->temporaryFiles(), 'No temporary file was left behind.');
  }

  /**
   * Data provider for ::testNewMediaObjectUsesBasenameOfNestedName().
   *
   * @return array
   *   Test cases: the client file name.
   */
  public static function providerNestedNames(): array {
    return [
      'Open Live Writer folder' => ['Open-Live-Writer/my-post_1234/image.png'],
      'parent traversal' => ['../../image.png'],
      'Windows separators' => ['Open-Live-Writer\\my-post_1234\\image.png'],
    ];
  }

  /**
   * Only image extensions are accepted, and nothing is stored otherwise.
   *
   * @dataProvider providerRejectedExtensions
   */
  #[DataProvider('providerRejectedExtensions')]
  public function testNewMediaObjectRejectsDisallowedExtension(string $name, string $bits): void {
    $result = $this->newMediaObject($name, $bits);
    $this->assertFalse($result);
    $this->assertSame(410, xmlrpc_errno(), (string) xmlrpc_error_msg());
    $this->assertSame('Error uploading file: Only files with the following extensions are allowed: jpg jpeg png gif webp.', (string) xmlrpc_error_msg());

    $this->assertSame([], $this->loadFiles(), 'No file entity was created.');
    $this->assertDirectoryDoesNotExist('public://olw');
  }

  /**
   * Data provider for ::testNewMediaObjectRejectsDisallowedExtension().
   *
   * @return array
   *   Test cases: the client file name, the contents.
   */
  public static function providerRejectedExtensions(): array {
    return [
      'php' => ['shell.php', '<?php phpinfo();'],
      'image name ending in php' => ['image.png.php', '<?php phpinfo();'],
      'svg' => ['image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
      'no extension' => ['image', 'GIF89a'],
    ];
  }

  /**
   * Insecure inner extensions are munged as core does for uploads.
   */
  public function testNewMediaObjectMungesInsecureInnerExtension(): void {
    $result = $this->newMediaObject('shell.php.png', $this->imageBytes());
    $this->assertIsArray($result, (string) xmlrpc_error_msg());

    $files = $this->loadFiles();
    $file = reset($files);
    $this->assertSame($this->expectedDirectory($file) . '/shell.php_.png', $file->getFileUri());
  }

  /**
   * An oversized upload is rejected before anything is written.
   */
  public function testNewMediaObjectRejectsOversizedFileBeforeSaving(): void {
    $bytes = $this->imageBytes();
    $this->config('blogapi.settings')
      ->set('media_max_filesize', strlen($bytes) - 1)
      ->save();

    $result = $this->newMediaObject('image.png', $bytes);
    $this->assertFalse($result);
    $this->assertSame(408, xmlrpc_errno(), (string) xmlrpc_error_msg());

    $this->assertSame([], $this->loadFiles(), 'No file entity was created.');
    $this->assertDirectoryDoesNotExist('public://olw');

    // Positive control: a file at the limit is accepted.
    $this->config('blogapi.settings')
      ->set('media_max_filesize', strlen($bytes))
      ->save();
    $this->assertIsArray($this->newMediaObject('image.png', $bytes), (string) xmlrpc_error_msg());
  }

  /**
   * A second upload with the same name is renamed, never replacing the first.
   */
  public function testNewMediaObjectRenamesDuplicateName(): void {
    $first = $this->imageBytes('image-test.png');
    $second = $this->imageBytes('image-1.png');
    $this->assertNotSame($first, $second);

    $first_result = $this->newMediaObject('image.png', $first);
    $second_result = $this->newMediaObject('image.png', $second);
    $this->assertIsArray($first_result, (string) xmlrpc_error_msg());
    $this->assertIsArray($second_result, (string) xmlrpc_error_msg());
    $this->assertNotSame($first_result['url'], $second_result['url']);

    $files = array_values($this->loadFiles());
    $this->assertCount(2, $files);
    $directory = $this->expectedDirectory($files[0]);
    $this->assertSame($directory . '/image.png', $files[0]->getFileUri());
    $this->assertSame($directory . '/image_0.png', $files[1]->getFileUri());
    $this->assertSame($first, file_get_contents($files[0]->getFileUri()));
    $this->assertSame($second, file_get_contents($files[1]->getFileUri()));
  }

  /**
   * The install defaults match the documented settings.
   */
  public function testMediaSettingsInstallDefaults(): void {
    $config = $this->config('blogapi.settings');
    $this->assertSame('public://olw', $config->get('media_directory'));
    $this->assertSame('', $config->get('media_max_filesize'));
  }

  /**
   * The base directory can be changed in configuration.
   *
   * @dataProvider providerConfiguredDirectories
   */
  #[DataProvider('providerConfiguredDirectories')]
  public function testNewMediaObjectUsesConfiguredDirectory(string $base, string $expected_prefix): void {
    $this->config('blogapi.settings')
      ->set('media_directory', $base)
      ->save();

    $result = $this->newMediaObject('image.png', $this->imageBytes());
    $this->assertIsArray($result, (string) xmlrpc_error_msg());

    $files = $this->loadFiles();
    $file = reset($files);
    $this->assertSame($this->expectedDirectory($file, $expected_prefix) . '/image.png', $file->getFileUri());
    $this->assertFileExists($file->getFileUri());
  }

  /**
   * Data provider for ::testNewMediaObjectUsesConfiguredDirectory().
   *
   * @return array
   *   Test cases: the configured base, the expected directory prefix.
   */
  public static function providerConfiguredDirectories(): array {
    return [
      'subdirectory' => ['public://uploads/blogapi', 'public://uploads/blogapi/'],
      'trailing slash' => ['public://uploads/blogapi/', 'public://uploads/blogapi/'],
      'stream wrapper root' => ['public://', 'public://'],
    ];
  }

  /**
   * A base directory without a valid stream wrapper is refused.
   *
   * @dataProvider providerInvalidDirectories
   */
  #[DataProvider('providerInvalidDirectories')]
  public function testNewMediaObjectRefusesInvalidConfiguredDirectory(string $base): void {
    $this->config('blogapi.settings')
      ->set('media_directory', $base)
      ->save();

    $result = $this->newMediaObject('image.png', $this->imageBytes());
    $this->assertFalse($result);
    $this->assertSame(409, xmlrpc_errno(), (string) xmlrpc_error_msg());
    $this->assertSame([], $this->loadFiles(), 'No file entity was created.');
  }

  /**
   * Data provider for ::testNewMediaObjectRefusesInvalidConfiguredDirectory().
   *
   * @return array
   *   Test cases: the configured base.
   */
  public static function providerInvalidDirectories(): array {
    return [
      'unknown scheme' => ['bogus://olw'],
      'bare path' => ['olw'],
    ];
  }

  /**
   * A name that fits the file name limit but not the stored URI is refused.
   *
   * The dated directory is part of the URI, so a name that passes the file
   * name length check can still be too long for the file entity's URI field.
   */
  public function testNewMediaObjectRejectsNameTooLongForUri(): void {
    $name = str_repeat('a', 236) . '.png';
    $this->assertSame(240, strlen($name));

    $result = $this->newMediaObject($name, $this->imageBytes());
    $this->assertFalse($result);
    $this->assertSame(410, xmlrpc_errno(), (string) xmlrpc_error_msg());
    $this->assertSame('Error uploading file: The file name is too long for the upload directory.', (string) xmlrpc_error_msg());

    $this->assertSame([], $this->loadFiles(), 'No file entity was created.');
    $this->assertDirectoryDoesNotExist('public://olw');
  }

  /**
   * Fault strings reach the client decoded exactly once.
   */
  public function testNewMediaObjectFaultMessageIsNotDoubleEncoded(): void {
    $result = $this->newMediaObject(str_repeat('a', 237) . '.png', $this->imageBytes());
    $this->assertFalse($result);
    $this->assertSame(410, xmlrpc_errno(), (string) xmlrpc_error_msg());
    $this->assertSame("Error uploading file: The file's name exceeds the 240 characters limit. Rename the file and try again.", (string) xmlrpc_error_msg());
  }

}

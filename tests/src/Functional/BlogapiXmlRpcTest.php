<?php

declare(strict_types=1);

namespace Drupal\Tests\blogapi\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\comment\Tests\CommentTestTrait;
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
   * The module ships no config schema yet.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   *
   * The Blog API code uses comment field definitions without declaring a
   * dependency on comment, so it is enabled here explicitly.
   */
  protected static $modules = [
    'xmlrpc',
    'node',
    'taxonomy',
    'comment',
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

}

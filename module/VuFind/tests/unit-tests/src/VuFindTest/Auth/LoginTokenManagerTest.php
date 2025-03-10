<?php

/**
 * Class AuthTokenTest
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2023
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  VuFindTest\Auth
 * @author   Jaro Ravila <jaro.ravila@helsinki.fi>
 * @license  https://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */

declare(strict_types=1);

namespace VuFindTest\Auth;

use Laminas\Config\Config;
use Laminas\Session\SaveHandler\SaveHandlerInterface;
use Laminas\Session\SessionManager;
use VuFind\Auth\LoginTokenManager;
use VuFind\Cookie\CookieManager;
use VuFind\Db\Service\UserServiceInterface;
use VuFind\Exception\LoginToken as LoginTokenException;

/**
 * Class LoginTokenManagerTest
 *
 * @category VuFind
 * @package  VuFindTest\Auth
 * @author   Jaro Ravila <jaro.ravila@helsinki.fi>
 * @license  https://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */
class LoginTokenManagerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Test login exception
     *
     * @return void
     */
    public function testTokenLoginException()
    {
        $cookieManager = $this->getCookieManager(
            [
              'loginToken' => '222;0;111',
            ]
        );
        $mockToken = $this->getMockLoginToken();
        $userService = $this->createMock(UserServiceInterface::class);
        $userService->expects($this->once())->method('getUserById')
            ->with($this->equalTo(0))
            ->willReturn($this->getMockUser());
        $tokenTable = $this->getMockLoginTokenTable();
        $tokenTable->expects($this->once())->method('matchToken')
            ->willReturn($mockToken);
        $loginToken = $this->getLoginToken($cookieManager, $tokenTable, $userService, false);

        // Expect exception due to browscap cache requirement
        $this->expectException(\VuFind\Exception\Auth::class);
        $loginToken->tokenLogin('123');
        $loginToken->requestIsFinished();
    }

    /**
     * Test logging in with invalid token
     *
     * @return void
     */
    public function testTokenLoginInvalidToken()
    {
        $cookieManager = $this->getCookieManager(
            [
              'loginToken' => '222;111',
            ]
        );
        $mockToken = $this->getMockLoginToken();
        $userService = $this->createMock(UserServiceInterface::class);
        $userService->expects($this->once())->method('getUserById')
            ->with($this->equalTo(0))
            ->willReturn($this->getMockUser());
        $tokenTable = $this->getMockLoginTokenTable();
        $tokenTable->expects($this->once())->method('matchToken')
            ->will($this->throwException(new LoginTokenException('Token does not match', 0)));
        $tokenTable->expects($this->once())->method('getByUserId')
            ->willReturn([$mockToken]);
        $loginToken = $this->getLoginToken($cookieManager, $tokenTable, $userService, true);
        $this->assertNull($loginToken->tokenLogin('123'));
    }

    /**
     * Test failed login
     *
     * @return void
     */
    public function testTokenLoginFail()
    {
        $userService = $this->createMock(UserServiceInterface::class);
        $cookieManager = $this->getCookieManager(
            [
              'loginToken' => '222;0;111',
            ]
        );
        $tokenTable = $this->getMockLoginTokenTable();
        $tokenTable->expects($this->once())->method('matchToken')
            ->willReturn(null);
        $loginToken = $this->getLoginToken($cookieManager, $tokenTable, $userService, true);
        $this->assertNull($loginToken->tokenLogin('123'));
    }

    /**
     * Get a mock user.
     *
     * @return User
     */
    protected function getMockUser()
    {
        $user = $this->createMock(\VuFind\Db\Entity\UserEntityInterface::class);
        $user->method('getId')->willReturn(0);
        return $user;
    }

    /**
     * Get a mock Login Token.
     *
     * @return User
     */
    protected function getMockLoginToken()
    {
        $tokenData = [
            ['token', '111'],
            ['user_id', 0],
            ['series', '222'],
            ['expires', 2],
            ['last_session_id', '333'],
        ];
        $token = $this->getMockBuilder(\VuFind\Db\Row\LoginToken::class)
            ->disableOriginalConstructor()
            ->getMock();
        $token->method('__get')
            ->willReturnMap($tokenData);
        $token->method('offsetGet')
            ->willReturnMap($tokenData);
        return $token;
    }

    /**
     * Get a mock user table.
     *
     * @return LoginTokenTable
     */
    protected function getMockLoginTokenTable()
    {
        $table = $this->getMockBuilder(\VuFind\Db\Table\LoginToken::class)
            ->disableOriginalConstructor()
            ->getMock();
        return $table;
    }

    /**
     * Get cookie manager
     *
     * @param array $cookies Cookies
     *
     * @return CookieManager
     */
    protected function getCookieManager(array $cookies): CookieManager
    {
        return new CookieManager(
            $cookies,
            '/first',
            'localhost',
            false,
            'SESS',
        );
    }

    /**
     * Get login token
     *
     * @param CookieManager $cookieManager cookie manager
     * @param LoginToken    $tokenTable    Login token table
     * @param User          $userTable     User table
     * @param bool          $browscapOk    Whether to emulate working browscap
     *
     * @return LoginTokenManager
     */
    protected function getLoginToken($cookieManager, $tokenTable, $userTable, $browscapOk)
    {
        $config = new Config([]);
        $saveHandler = $this->createMock(SaveHandlerInterface::class);
        $sessionManager = $this->getMockBuilder(SessionManager::class)->getMock();
        $sessionManager->expects($this->any())
            ->method('getSaveHandler')
            ->willReturn($saveHandler);
        $mailer = $this->getMockBuilder(\VuFind\Mailer\Mailer::class)
            ->disableOriginalConstructor()
            ->getMock();
        $viewRenderer = $this->getMockBuilder(\Laminas\View\Renderer\RendererInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $browscap = $this->getMockBuilder(\BrowscapPHP\BrowscapInterface::class)->getMock();
        if ($browscapOk) {
            $browser = new \stdClass();
            $browser->browser = 'Test Browser';
            $browser->platform = 'PHPUnit';
            $browscap->expects($this->any())
                ->method('getBrowser')
                ->willReturn($browser);
        } else {
            $browscap->expects($this->any())
                ->method('getBrowser')
                ->willThrowException(new \BrowscapPHP\Exception('Simulated exception'));
        }

        return new LoginTokenManager(
            $config,
            $userTable,
            $tokenTable,
            $cookieManager,
            $sessionManager,
            $mailer,
            $viewRenderer,
            function () use ($browscap) {
                return $browscap;
            }
        );
    }
}

<?php
namespace Page\Main;

use Aqua\Core\App;
use Aqua\Http\Request;
use Aqua\Log\ErrorLog;
use Aqua\Ragnarok\Exception\LoginServerException;
use Aqua\Ragnarok\Ragnarok as _Ragnarok;
use Aqua\Ragnarok\Account as RoAccount;
use Aqua\UI\Menu;
use Aqua\UI\Template;
use Aqua\User\Account as UserAccount;
use Aqua\Ragnarok\Server;
use Aqua\Site\Page;
use Aqua\UI\Form;

class Ragnarok
extends Page
{
	/**
	 * @var \Aqua\Site\Page
	 */
	public $page;
	/**
	 * @var \Aqua\Ragnarok\Server
	 */
	public $server;

	public function run()
	{
		$this->server = &App::$activeServer;
		$menu = new Menu;
		if(!App::user()->loggedIn()) return;
		if($this->server instanceof Server) {
			$menu->append('register', array(
					'title' => __('ragnarok', 'register-account'),
					'url' => $this->server->url(array( 'action' => 'register' ))
				));
			if($this->server->login->getOption('link-accounts')) {
				$menu->append('link-account', array(
						'title' => __('ragnarok', 'link-account'),
						'url' => $this->server->url(array( 'action' => 'link' ))
					));
			}
		} else {
			$menu->append('register', array(
					'title' => __('ragnarok', 'register-account'),
					'url' => ac_build_url(array(
							'path' => array( 'ragnarok' ),
							'action' => 'register'
						))
				));
		}
		$this->theme->set('menu', $menu);
	}

	public function index_action()
	{
		$tpl = new Template;
		$tpl->set('page', $this);
		echo $tpl->render('ragnarok/main');
	}

	public function register_action()
	{
		if(!\Aqua\HTTPS && App::settings()->get('ssl', 0) >= 1) {
			$this->response->status(301)->redirect(App::request()->uri->url(array( 'protocol' => 'https://' )));
			return;
		}
		try {
			$user = App::user();
			$frm = new Form($this->request);
			$frm->input('username', true)
				->type('text')
				->required()
				->setLabel(__('ragnarok', 'username'));
			$frm->input('password', false)
				->type('password')
				->required()
				->setLabel(__('ragnarok', 'password'));
			$frm->input('password_r', false)
				->type('password')
				->required()
				->setLabel(__('ragnarok', 'password-repeat'));
			$frm->radio('gender', true)
				->value(array(
					'male'   => __('ragnarok', 'male'),
					'female' => __('ragnarok', 'female')
				))
				->checked('male')
				->setLabel(__('ragnarok', 'gender'));
			if(!$this->server) {
				$values = array();
				foreach(Server::$servers as $server) {
					$values[$server->key] = $server->name;
				}
				reset(Server::$servers);
				$frm->select('server')
					->required()
					->setLabel(__('ragnarok', 'server'))
					->setWarning(__('ragnarok', 'invalid-server-selected'))
					->value($values);
			}
			$frm->token('ragnarok-registration');
			$frm->submit();
			$self = $this;
			$frm->validate(function(Form $frm, &$message) use ($self) {
				if(!($server = $self->server) || (Server::$serverCount > 1 && !($server = Server::get($frm->request->getString('server')))) || (!$server = current(Server::$servers))) {
					$message = __('ragnarok', 'invalid-server-selected');
					return false;
				}
				$error = false;
				$username = $frm->request->data('username');
				$password = $frm->request->data('password');
				$password_r = $frm->request->data('password_r');
				$max = (int)$server->login->getOption('max-accounts');
				if($server->login->checkValidUsername($username, $mes) !== Server\Login::FIELD_OK) {
					$frm->field('username')->setWarning($mes);
					$error = true;
				} else if($server->login->checkValidPassword($password, $mes) !== Server\Login::FIELD_OK) {
					$frm->field('password')->setWarning($mes);
					$error = true;
				} else if($password !== $password_r) {
					$frm->field('password_r')->setWarning(__('ragnarok', 'password-mismatch'));
					$error = true;
				} else if(($count = $server->login->countAccounts(App::user()->account->id)) >= $max) {
					$message = __('ragnarok', 'registration-account-limit', $count);
					$error = true;
				} else if($server->login->exists($username)) {
					$frm->field('username')->setWarning(__('ragnarok', 'username-taken'));
					$error = true;
				}
				return !$error;
			});
			if($frm->status !== Form::VALIDATION_SUCCESS) {
				$this->title = $this->theme->head->section = __('ragnarok', 'register-account');
				if($this->server) {
					$this->title = __('ragnarok', 'register-account-server', $this->server->name);
				}
				$tpl = new Template;
				$tpl->set('form', $frm)
					->set('page', $this);
				echo $tpl->render('ragnarok/register');
				return;
			}
			if(!($server = $this->server)) {
				$server = Server::get($this->request->getString('server'));
			}
			$this->response->status(302);
			try {
				$acc = $server->login->register(
					$this->request->getString('username'),
					$this->request->getString('password'),
					$user->account->email,
					$this->request->getString('gender') === 'female' ? 'F' : 'M',
					$user->account->birthDate,
					null,
					ROAccount::STATE_NORMAL,
					$user->account
				);
				$user->addFlash('success', __('ragnarok', 'registration-completed'), __('ragnarok', 'registration-success'));
				$this->response->redirect($acc->url());
				return;
			} catch(\Exception $exception) {
				ErrorLog::logSql($exception);
				try {
					if(isset($acc) && $acc instanceof ROAccount) {
						$server->login->deleteAccount($acc->id);
						$server->login->fetchCache(null, true);
					}
				} catch(\Exception $exception2) {
					ErrorLog::logSql($exception2);
				}
				$this->response->redirect(App::user()->request->uri->url());
				$user->addFlash('error', null, __('application', 'unexpected-error'));
				return;
			}
		} catch(\Exception $exception) {
			ErrorLog::logSql($exception);
			$this->error(500, __('application', 'unexpected-error-title'), __('application', 'unexpected-error'));
		}
	}

	public function link_action()
	{
		try {
			if(!$this->server->login->getOption('link-accounts')) {
				$this->error(404);
				return;
			}
			if(!\Aqua\HTTPS && App::settings()->get('ssl', 0) >= 1) {
				$this->response->status(301)->redirect(App::request()->uri->url(array( 'protocol' => 'https://' )));
				return;
			}
			$frm = new Form($this->request);
			if($this->server) {
				$this->title = __('ragnarok', 'link-account-server', $this->server->name);
			} else {
				$servers = array();
				foreach(Server::$servers as $server) {
					$servers[$server->key] = htmlspecialchars($server->name);
				}
				reset(Server::$servers);
				$frm->select('server')
			        ->setLabel(__('ragnarok-account', 'server'))
					->setWarning(__('ragnarok-account', 'invalid-server-selected'))
			        ->value($servers);
			}
			$pincode_len = (int)App::settings()->get('ragnarok')->get('pincode_max_len', 4);
			$frm->input('username')
				->type('text')
				->attr('maxlength', 255)
				->required()
		        ->setLabel(__('ragnarok-account', 'username'));
			$frm->input('password')
		        ->type('password')
				->required()
		        ->setLabel(__('ragnarok-account', 'password'));
			$frm->input('pincode')
		        ->type('password')
				->attr('maxlen', $pincode_len)
				->attr('size', $pincode_len)
		        ->setLabel(__('ragnarok-account', 'pincode'));
			$frm->submit();
			$self = $this;
			$account = null;
			$frm->validate(function(Form $frm, &$message) use ($self, &$account) {
					$username = trim($frm->request->getString('username'));
					$password = $frm->request->getString('password');
					$pincode  = $frm->request->getString('pincode');
					if(!$self->server->login->checkCredentials($username, $password, $pincode)) {
						$message = __('ragnarok-account', 'invalid-credentials');
						return false;
					}
					$account = $self->server->login->get($username, 'username');
					if($account->owner === App::user()->account->id) {
						$message = __('ragnarok-account', 'already-linked');
						return false;
					} else if($account->owner) {
						$message = __('ragnarok-account', 'invalid-credentials');
						return false;
					}
					return true;
				});
			if($frm->status !== Form::VALIDATION_SUCCESS) {
				$this->title = $this->theme->head->section = __('ragnarok-account', 'link-account');
				$tpl = new Template;
				$tpl->set('form', $frm)
			        ->set('page', $this);
				echo $tpl->render('ragnarok/link');
				return;
			}
			try {
				/**
				 * @var $account \Aqua\Ragnarok\Account
				 */
				$account->link(App::user()->account);
				App::user()->addFlash('error', null, __('ragnarok', 'account-linked'));
				$this->response->status(302)->redirect($account->url());
			} catch(\Exception $exception) {
				ErrorLog::logSql($exception);
				App::user()->addFlash('error', null, __('application', 'unexpected-error'));
				$this->response->status(302)->redirect(App::request()->uri->url());
			}
		} catch(\Exception $exception) {
			ErrorLog::logSql($exception);
			$this->error(500, __('application', 'unexpected-error-title'), __('application', 'unexpected-error'));
		}
	}
}

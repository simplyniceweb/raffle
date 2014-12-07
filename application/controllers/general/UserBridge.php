<?php
namespace general;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use general\Tools;

class UserBridge {

	public static function routing(Application $app)
	{
		$routing = $app['controllers_factory'];

		// Login
		$routing->match('/', 'general\UserBridge::index')->bind('login');
		
		// Registration
		$routing->match('/pre-registration', 'general\UserBridge::preRegistration')->bind('pre-registration');
		$routing->match('/registration', 'general\UserBridge::registration')->bind('registration');
		$routing->match('/registration-process', 'general\UserBridge::registrationProcess')->bind('registration-process');
		
		// Reset password
		$routing->match('/reset-password', 'general\UserBridge::resetPassword')->bind('reset-password');
		$routing->match('/reset-password-process', 'general\UserBridge::resetPasswordProcess')->bind('reset-password-process');

		return $routing;
	}	

	public function index(Request $req, Application $app)
	{
		$role = NULL;
		$token = $app['security']->getToken();

		if (null !== $token) {
		    $user = $token->getUser();
		}

		if ($app['security']->isGranted("ROLE_ADMIN"))
		{
			return $app->redirect($app['url_generator']->generate("admin_overview"));
		}
		else if ($app['security']->isGranted("ROLE_USER"))
		{
			return $app->redirect($app['url_generator']->generate("user_overview"));
		}

		$view = array(
			'title' => 'Raffle',
			'err_'  => $app['session']->getFlashBag()->get('err_'),
			'error' => $app['security.last_error']($req),
			'message' => $app['session']->getFlashBag()->get('message'),
			'last_username' => $app['session']->get('_security.last_username'),
		);

		return $app['twig']->render('general/index.twig', $view);
	}
	
	public function preRegistration(Request $req, Application $app)
	{
		/* TODO: Validate OR # */
		$preRegData = array(
			'firstname' => $req->get('firstname'),
			'lastname' => $req->get('lastname'),
			'or_num' => $req->get('or_num'),
		);

		$app['session']->set('registration', $preRegData);
        
        return Tools::redirect($app, 'registration');
	}
	
	public function registration(Request $req, Application $app)
	{
		$preReg = $app['session']->get('registration');
		
		$view = array(
			'title' => 'Registration',
			'firstname' => $preReg['firstname'],
			'lastname' => $preReg['lastname'],
			'or_num' => $preReg['or_num'],
		);

		return $app['twig']->render('general/registration.twig', $view);
	}
	
	public function registrationProcess(Request $req, Application $app)
	{
		/* TODO: Check for email duplicate */
		$preReg = $app['session']->get('registration');
		
		$firstname = $req->get('firstname'); // $preReg['firstname']
		$lastname = $req->get('lastname'); // $preReg['lastname']
		$orNum = $preReg['or_num'];
		
		$email = $req->get('email');
		$password = $req->get('password');
		$mobile = $req->get('mobile');
		$birthday = $req->get('birthday');
		$address = $req->get('address');
		
		$password = $app['security.encoder.digest']->encodePassword($password, '');
		
		$user = new \models\Users;
		$user->setEmail($email);
		$user->setPassword($password);
		$user->setRoles('ROLE_USER');
		$user->setViewStatus(5);
		$user->setCreatedAt("now");
		$user->setModifiedAt("now");
		$app['orm.em']->persist($user);
		$app['orm.em']->flush();
		
		$profile = new \models\Profiles;
		$profile->setFirstname($firstname);
		$profile->setLastname($lastname);
		$profile->setMobile($mobile);
		$profile->setBirthday(new \DateTime($birthday));
		$profile->setAddress($address);
		$profile->setUser($user);
		$profile->setViewStatus(5);
		$profile->setCreatedAt("now");
		$profile->setModifiedAt("now");
		$app['orm.em']->persist($profile);
		$app['orm.em']->flush();
		
		$receipt = new \models\Receipts;
		$receipt->setReceiptNumber($orNum);
		$receipt->setUser($user);
		$receipt->setViewStatus(5);
		$receipt->setCreatedAt("now");
		$receipt->setModifiedAt("now");
		$app['orm.em']->persist($receipt);
		$app['orm.em']->flush();
        
        Tools::autoLogin($app, $user->getEmail());
		
		return Tools::redirect($app, 'user_overview');
	}

	public function resetPassword(Request $req, Application $app)
	{
		$msg = "reset_failed";
		$email = $req->get('email');
		$generatedUrl = "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]-process";
		
		$user = Tools::findOneBy($app, "\Users", array("email" => $email, "view_status" => 5));
		if ( ! empty($user))
		{
			$token = md5(uniqid()) . md5($email);
			$url = $generatedUrl . '?token=' . $token;
			$html = $app['twig']->render('general/forgot-password/msg.forgot-password.twig', array( "url" => $url ));
	
			$message = \Swift_Message::newInstance()
						->setSubject("Reset Password")
						->setFrom(array("admin@raffle.com" => "Raffle Draw"))
						->setTo(array($email))
						->addPart( $html , 'text/html' );
	
			if ( $app['mailer']->send($message))
			{
				$user->setToken($token);
				$user->setModifiedAt("now");
				$app['orm.em']->persist($user);
				$app['orm.em']->flush();
				
				$msg = "reset_sent";
			}
		}

		$app['session']->getFlashBag()->set('message', $msg);

         return Tools::redirect($app, 'login');
	}
	
	public function resetPasswordProcess(Request $req, Application $app)
	{
		$msg = NULL;
		$token = $req->get('token');
		$password = $req->get('password');
		$confirm_password = $req->get('confirm_password');

		$userAuthToken = Tools::findOneBy($app, "\Users", array("token" => $token, "view_status" => 5));

		if (empty($userAuthToken))
		{
			$app['session']->getFlashBag()->set('message', 'invalid_reset_token');
            return Tools::redirect($app, 'login');
		}
		
		if ( ! empty($password) && $password == $confirm_password)
		{
			$password = $app['security.encoder.digest']->encodePassword($password, '');
			
			$userAuthToken->setPassword($password);
			$userAuthToken->setToken(NULL);
			$userAuthToken->setModifiedAt("now");
			$app['orm.em']->persist($userAuthToken);
			$app['orm.em']->flush();

			$app['session']->getFlashBag()->set('message', 'reset_success');
			return Tools::redirect($app, 'login');
		}
		else if ( ! empty($password) && $password != $confirm_password)
		{
			$msg = "Password did not match.";
		}

		$view = array(
			'title' => 'Change Password',
			'token_' => $token,
			'message' => $msg,
		);
		
		return $app['twig']->render('general/forgot-password/form.forgot-password.twig', $view);
	}
}
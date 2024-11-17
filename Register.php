<?php

namespace OzzModz\VerifyEmail\XF\Pub\Controller;

use OzzModz\VerifyEmail\Globals;
use OzzModz\VerifyEmail\Service\User\EmailVerifier as EmailVerifierSvc;
use XF\Mvc\Reply\View as ViewReply;
use XF\Mvc\Reply\Redirect as RedirectReply;
use XF\Mvc\Reply\Exception as ExceptionReply;
use XF\Service\AbstractService;
use OzzModz\VerifyEmail\XF\Service\User\Registration as ExtendedUserRegistrationSvc;

class Register extends XFCP_Register
{
    public function actionIndex()
    {
        $emailVerificationCode = $this->filter('email_verification_code', 'str');
        if ($emailVerificationCode)
        {
        }
        else if (!$this->filter('has_email_verification_code', 'bool'))
        {
            return $this->rerouteController(__CLASS__, 'withoutVerificationCode');
        }

        $reply = parent::actionIndex();

        if ($reply instanceof ViewReply)
        {
            $reply->setParam('ozzModzEmailVerificationCode', $emailVerificationCode);
        }

        return $reply;
    }

    /**
     * @return RedirectReply|ViewReply
     *
     * @throws ExceptionReply
     */
    public function actionWithoutVerificationCode()
    {
        $this->assertRegistrationActive();
        if (\XF::visitor()->user_id)
        {
            throw $this->exception($this->notFound());
        }

        if ($this->isPost())
        {
            if (!$this->captchaIsValid())
            {
                throw $this->exception($this->error(
                    \XF::phrase('did_not_complete_the_captcha_verification_properly')
                ));
            }

            $emailAddress = $this->filter('email_address', 'str');
            
            // Check if the email is disposable
            if ($this->isDisposableEmail($emailAddress)) {
                throw $this->exception($this->error(
                    \XF::phrase('Sorry, we do not allow disposable email domains to prevent spam.')
                ));
            }
            
            $emailVerifierSvc = $this->getEmailVerifierSvcForOzzModz();
            if (!$emailVerifierSvc->createValidationEntry($this->session(), $emailAddress, $error))
            {
                throw $this->exception($this->error($error));
            }

            return $this->redirect($this->buildLink('register', null, [
                'has_email_verification_code' => true
            ]), \XF::phrase('ozzModzVerifyEmail_email_verification_code_has_been_emailed_to_you'));
        }

        $viewParams = [];
        return $this->view(
            'OzzModz\VerifyEmail\XF:Register\ConfirmEmail',
            'ozzModzVerifyEmail_register_confirm_email',
            $viewParams
        );
    }

    protected function setupRegistration(array $input)
    {
        if (\array_key_exists('email', $input))
        {
            unset($input['email']);
        }

        Globals::$emailValidationCode = $this->filter('email_verification_code', 'str');

        try
        {
            return parent::setupRegistration($input);
        }
        finally
        {
            Globals::$emailValidationCode = null;
        }
    }

    /**
     * @return AbstractService|EmailVerifierSvc
     */
    protected function getEmailVerifierSvcForOzzModz()
    {
        return $this->service('OzzModz\VerifyEmail:User\EmailVerifier');
    }

    /**
     * Check if an email address is disposable using the API
     *
     * @param string $email
     * @return bool
     */
    protected function isDisposableEmail(string $email): bool
    {
        $apiUrl = 'https://api.api-aries.online/v1/checkers/proxy/email/?email=' . urlencode($email);
        $headers = [
            'APITOKEN: API Token here', // GEt api token "https://dashboard.api-aries.online/"
        ];

        $curl = curl_init($apiUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            return isset($responseData['disposable']) && strtolower($responseData['disposable']) === 'yes';
        }

        // Log API issue for further debugging
        \XF::logError('Disposable email API check failed for email: ' . $email);
        return false;
    }
}

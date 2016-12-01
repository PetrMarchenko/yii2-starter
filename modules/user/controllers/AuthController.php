<?php

namespace app\modules\user\controllers;

use app\modules\mailTemplate\models\Mail;
use app\modules\mailTemplate\models\MailTemplate;
use app\modules\user\models\forms\ChangePasswordForm;
use app\modules\user\models\forms\LoginForm;
use app\modules\user\models\forms\RecoveryForm;
use app\modules\user\models\Hash;
use app\modules\user\models\User;
use Yii;
use yii\base\Exception;
use yii\filters\AccessControl;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use app\modules\user\models\forms\RegistrationForm;
use yii\web\NotFoundHttpException;

/**
 * AuthController for the `user` module
 */
class AuthController extends Controller
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'only' => ['registration', 'login', 'recovery'],
                'rules' => [
                    [
                        'actions' => ['registration', 'login', 'recovery'],
                        'allow' => true,
                        'roles' => ['?'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
        ];
    }

    /**
     * @return string|\yii\web\Response
     * @throws Exception
     * @throws NotFoundHttpException
     */
    public function actionRegistration()
    {
        $registrationForm = new RegistrationForm();

        if ($registrationForm->load(Yii::$app->request->post()) && $registrationForm->validate()) {
            $user = new User();

            if (!$user = $user->create($registrationForm)) {
                throw new Exception('User could not be created.');
            }

            if (!$mailTemplate = MailTemplate::findByKey('REGISTER_CONFIRM')) {
                throw new NotFoundHttpException('Template does not exist.');
            }

            $hash = new Hash();
            $mailTemplate->replacePlaceholders([
                'name' => $user->first_name,
                'link' => Yii::$app->urlManager->createAbsoluteUrl([
                    'user/auth/confirm-registration',
                    'hash' => $hash->generate(Hash::TYPE_REGISTER, $user->id)
                ]),
            ]);

            $mail = new Mail();
            $mail->setTemplate($mailTemplate);
            $mail->sendTo($user->email);
            Yii::$app->session->setFlash(
                'success',
                Yii::t('user', 'Please, check your email to confirm registration.')
            );
        }

        return $this->render('registration', [
            'model' => $registrationForm,
        ]);
    }

    /**
     * @return bool|\yii\web\Response
     * @throws BadRequestHttpException
     * @throws Exception
     */
    public function actionConfirmRegistration()
    {
        if (!$hash = Yii::$app->request->get('hash')) {
            throw new BadRequestHttpException();
        }

        if (!$user = User::findByHash($hash)) {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
        $user->status = User::STATUS_ACTIVE;
        $user->update();
        $user->login();

        return $this->goHome();
    }

    /**
     * @return string|\yii\web\Response
     * @throws Exception
     */
    public function actionLogin()
    {
        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        $loginForm = new LoginForm();
        if ($loginForm->load(Yii::$app->request->post()) && $loginForm->validate()) {

            $user = User::findByEmail($loginForm->email);

            if (!$user || !$user->validatePassword($loginForm->password)) {
                Yii::$app->session->setFlash('danger', Yii::t('user', 'Incorrect email or password.'));
            } elseif ($user && $user->status != User::STATUS_ACTIVE) {
                Yii::$app->session->setFlash('danger', Yii::t('user', 'Your account is not active.'));
            } else {
                $user->login();
                return $this->goBack();
            }
        }
        return $this->render('login', [
            'model' => $loginForm,
        ]);
    }

    /**
     * Sends link for password recovery on user email
     *
     * @throws Exception
     * @throws NotFoundHttpException
     */
    public function actionRecovery()
    {
        $recoveryForm = new RecoveryForm();

        if ($recoveryForm->load(Yii::$app->request->post()) && $recoveryForm->validate()) {

            if (!$user = User::findByEmail($recoveryForm->email)) {
                Yii::$app->session->setFlash('danger', Yii::t('user', 'User does not exist.'));
            } elseif ($user && $user->status != User::STATUS_ACTIVE) {
                Yii::$app->session->setFlash('danger', Yii::t('user', 'User is not active.'));
            } else {

                if (!$mailTemplate = MailTemplate::findByKey('CHANGE_PASSWORD')) {
                    throw new NotFoundHttpException('Template does not exist.');
                }

                $hash = Hash::findByUserId($user->id);
                $mailTemplate->replacePlaceholders([
                    'name' => $user->first_name,
                    'link' => Yii::$app->urlManager->createAbsoluteUrl([
                        'user/auth/change-password',
                        'hash' => $hash->generate(Hash::TYPE_RECOVER, $user->id)
                    ]),
                ]);

                $mail = new Mail();
                $mail->setTemplate($mailTemplate);
                $mail->sendTo($user->email);

                Yii::$app->session->setFlash(
                    'success',
                    Yii::t('user', 'Please check your email and follow instructions to recover password.')
                );
            }
        }
        return $this->render('recovery', [
            'model' => $recoveryForm,
        ]);
    }

    /**
     * @return string|\yii\web\Response
     * @throws BadRequestHttpException
     * @throws NotFoundHttpException
     */
    public function actionChangePassword()
    {
        if (!$hash = Yii::$app->request->get('hash')) {
            throw new BadRequestHttpException();
        }
        if (!$user = User::findByHash($hash)) {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
        $changePasswordForm = new ChangePasswordForm();

        if ($changePasswordForm->load(Yii::$app->request->post()) && $changePasswordForm->validate()) {
            $user->password = Yii::$app->security->generatePasswordHash($changePasswordForm->newPassword);
            $user->update();
            $user->login();

            return $this->goHome();
        }
        return $this->render('change-password', [
            'model' => $changePasswordForm,
        ]);
    }

}
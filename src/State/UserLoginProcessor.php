<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserLoginProcessor implements ProcessorInterface
{
    public function __construct(private UserRepository $repository, private UserPasswordHasherInterface $userPasswordEncoder)
    {

    }

    /**
     * @param   User      $data
     * @param   Operation  $operation
     * @param   array      $uriVariables
     * @param   array      $context
     *
     * @return User
     * @throws \Exception
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = [])
    {
        $user = $this->repository->findOneByLogin($data->getEmail());
        if ($user instanceof  User) {

            if (!$this->userPasswordEncoder->isPasswordValid($user,  $data->getPlainPassword())) {
                throw new AccessDeniedHttpException();
            }

            $user->setToken(bin2hex(random_bytes(60)));
            $this->repository->save($user, true);
            return  $user;
        }

        throw new NotFoundHttpException();
    }
}

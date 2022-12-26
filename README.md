# API platform для начинающих

![logo](https://api-platform.com/static/worker-68554947816ddd3cc6dd87a5860dac7f.jpg)

[API platform](https://api-platform.com) это полнофункциональный REST API, который вы получите за считанные минуты.
Вот неполный список фич:
- Генерация CRUD
- Поддержка GraphQL
- Машиночитаемая документация API в форматах Hydra и Swagger/Open API, гененрится из метаданных PHPDoc, Serializer, Validator и Doctrine ORM / MongoDB ODM
- Хорошая удобочитаемая документация, созданная с использованием пользовательского интерфейса Swagger (включая песочницу) и / или ReDoc
- Пагинация
- Куча фильтров
- Проверка с использованием компонента Symfony Validator (с поддержкой групп)
- Расширенные правила аутентификации и авторизации
- Расширенная сериализация благодаря компоненту Symfony Serializer (поддержка групп, встраивание отношений, максимальная глубина...)
- Поддержка JWT и OAuth
- Файлы и \DateTime, сериализация и десериализация
- Все полностью настраивается благодаря мощной системе событий и сильному ООП.


После изучения этого материала ты узнаешь: 
 - Как с помощью API platform создать CRUD и получить документацию swagger
 - Добавим операцию регистрации пользователя и аутентификацию через токен 
 - Добавим доступы к операциям и позволим менять пароль только себе

## Начальные условия

 Для работы, нам потребуется чистенький проект на Symfony

```php
 composer create-project symfony/skeleton
```
 Допускаю, что проект открывается по ссылке http://localhost/

 Для запуска проекта локально, можно воспользоваться этим [docker compose]()

## Устанавливаем API platform

```console
composer require api
```
После чего, в проекте появятся все необходимые компоненты, включая ORM Doctrine


Создадим сущность пользователя

```console
php bin/console make:user
```

И теперь, давай добавим просто атрибут
**[ApiResource]** у этой сущности
 ```php
 namespace App\Entity;

 use ApiPlatform\Metadata\ApiResource;
 
#[ApiResource]
#[ORM\Entity(repositoryClass: UserRepository::class)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
...
```

Вот и всё готово! 
Мы создали полноценное REST Api.

Заходи по ссылке
http://localhost/api

И видим, что у нас появились методы для CRUD операций.
По сути, мы написали одну строчку кода, добавив один атрибут,
и уже имеем такой мощный функционал.



Отлично, движемся дальше.

## Делаем метод создание пользователя
Для этого нам необходимо добавить поле токен к сущности пользователя

```console
php bin/console make:entity User
```
добавим поле **token**

В самой сущности, добавим поле *plainPassword*, которой добавим атрибут
```php 
use Symfony\Component\Serializer\Annotation\SerializedName;
//....

class User implements UserInterface, PasswordAuthenticatedUserInterface
{
//...

#[SerializedName('password')]
private $plainPassword;


  /**
     * @return mixed
     */
    public function getPlainPassword()
    {
        return $this->plainPassword;
    }

    /**
     * @param   mixed  $plainPassword
     */
    public function setPlainPassword($plainPassword): void
    {
        $this->plainPassword = $plainPassword;
    }

  /**
     * @see UserInterface
     */
    public function eraseCredentials()
    {
        // If you store any temporary, sensitive data on the user, clear it here
         $this->plainPassword = null; // <--- Раскоментируем эту строчку 
    }
}
```

## Создаем процессор для пароля

[Смотри документацию про Процессоры состояний](https://github.com/kxxb/docs-ru/blob/3.0/core/state-processors-ru.md)

Далее нам необходимо реализовать процессор состояний, 
который будет содержать логику хэширования пароля и добавления ролей пользователю по умолчанию.

После чего мы сможем использовать этот процессор в операциях для создания пользователя и изменения пароля. 

Поехали
```console
php bin/console make:state-processor UserPasswordHasherProcessor
```

Регистрируем его в конфиге  **services.yaml**
```yaml
#services.yaml
services:
  
  App\State\UserPasswordHasherProcessor:
    bind:
      $processor: '@api_platform.doctrine.orm.state.persist_processor'


```

Сам процессор будет выглядеть так. 
```php
<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserPasswordHasherProcessor implements ProcessorInterface
{
    public function __construct(private readonly ProcessorInterface $processor, private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }


    /**
     * @param   User      $data
     * @param   Operation  $operation
     * @param   array      $uriVariables
     * @param   array      $context
     *
     * @return mixed
     * @throws \Exception
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = [])
    {
        if (!$data->getPlainPassword()) {
            return $this->processor->process($data, $operation, $uriVariables, $context);
        }

        $hashedPassword = $this->passwordHasher->hashPassword(
            $data,
            $data->getPlainPassword()
        );
        $data->setPassword($hashedPassword);
        $data->eraseCredentials();
        // Если это операция по созданию нового пользователя, то генерим токе и назначаем роль по умолчанию 
        if ($operation instanceof Post){
            $data->setToken(bin2hex(random_bytes(60)));
            $data->setRoles($data->getRoles());
        }

        return $this->processor->process($data, $operation, $uriVariables, $context);
    }
}


```

## Настраиваем операции

Пришло время, связать наш ресурс API с логикой, описанной в процессоре. 
Для этого добавим атрибут operations к сущности User и пропишем все необходимые операции в виде методов API.

Обрати внимание, я указал созданный нами процессор *UserPasswordHasherProcessor* 
в операциях Post() и Patch(). А так же, в операции Post() добавил параметр *uriTemplate* со значением */registration*, 
что изменит uri у этого метода с */api/users* на */api/registration*.


```php
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Get;

...

#[ApiResource(
operations: [
    new GetCollection(),
    new Post(processor: UserPasswordHasherProcessor::class, 
            uriTemplate: '/registration'),
    new Get(),
    new Patch(processor: UserPasswordHasherProcessor::class),
    new Delete(),
    ]
)]

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
```

Отлично, давай посмотрим на то, как сейчас выглядит swagger.
И видим, то что раньше у нас были поля в разделе Example value, а сейчас пусто.
Все нормально! Так как я переопределил, каждый метод в параметре *operations*, 
API Platform не понимает, какие поля ему нужно использовать для каждого метода как входные параметры, 
и какие поля будут отображены после успешной работы метода. 
Пришло время настроить сериализацию и нормализацию данных.

## Группы сериализации и нормализации

[Смотри документацию про Сериализацию](https://github.com/kxxb/docs-ru/blob/3.0/core/serialization-ru.md)

Для этого в атрибуте *ApiResource* в сущности *User* опишем параметры 
*normalizationContext* для чтения данных из сущности, и *denormalizationContext* для записи данных в сущность.


```php
use Symfony\Component\Serializer\Annotation\Groups;
...

#[ApiResource(
        normalizationContext: ['groups' => ['user:read']],
        denormalizationContext: ['groups' => ['user:create', 'user:update']],
        operations: [
```

А дальше, назначим эти группы для полей в сущности *User*. Так, чтобы для создания пользователя мы запрашивали только логин и пароль, 
а возвращали токен.

```php

class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Groups(['user:create', 'user:update'])]
    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 255)]
    #[Groups(['user:read'])]
    private ?string $token = null;


    #[Groups(['user:create', 'user:update'])]
    #[SerializedName('password')]
    private $plainPassword;
```

Посмотрим, как теперь выглядит в Swagger **Example value** в методе */api/registration*.
Обновим страничку с описанием и видим что, все так как и запланировали.

```json
{
  "email": "string",
  "password": "string"
}
```

И в ответе мы видим, что будет возращен токен

```json
{
  "@context": "string",
  "@id": "string",
  "@type": "string",
  "token": "string"
}
```
Ты не поверишь, но метод регистрации уже работает!
Можно уже сейчас нажать кнопку execute и все сработает!
Опа, ошибка вывалилась!

Ну конечно! А ты настроил соединение с БД и выполнил миграцию данных?

## БД и миграции

Пришло время настроить подключение и заполнить БД. 
```dotenv
#.env
DATABASE_URL="mysql://root:root@mysql:3306/app?serverVersion=8&charset=utf8mb4"
```

Создадим миграцию
```console
php bin/console make:migration
```
И выполним её 
```console
php bin/console doctrine:migrations:migrate
```

## Регистрируем пользователя через API
Заполняем данные в Swagger **Example value** в методе */api/registration*
и нажимаем  execute

Это же amazing какой-то!

Кстати, метод Patch тоже работает. И в нем так же можно изменить два поля
```json
{
  "email": "string",
  "password": "string"
}
```
И в ответ вернется значение поля token. Только токен не будет генерироваться вновь, 
так как в *UserPasswordHasherProcessor*, есть такое условие:
```php
    ...
    if ($operation instanceof Post){ // <- только для метода Post 
        $data->setToken(bin2hex(random_bytes(60)));
        $data->setRoles($data->getRoles());
    }
    
```

## Авторизация
В swagger, сверху есть такая кнопка **Authorize**, нажав на которую, мы видим...пустое окно.
Сейчас мы настроим наше API чтобы он мог авторизовывать пользователя через токен, 
а в swagger мы могли использовать этот токен.

Начнем с настройки API Platform. Добавим такой конфиг *config/packages/api_platform.yaml*

```yaml
#config/packages/api_platform.yaml
api_platform:
  # The title of the API.
  title: 'Edu API'

  # The description of the API.
  description: 'Edu API description'

  # The version of the API.
  version: '0.0.1'

  # Set this to false if you want Webby to disappear.
  show_webby: false

  mapping:
    paths: ['%kernel.project_dir%/src/Entity']
  patch_formats:
    json: ['application/merge-patch+json']
  swagger:
    versions: [3]
    api_keys:
      Bearer:
        name: AUTH-TOKEN
        type: header

```
И теперь нажав на кнопку **Authorize**, появится форма, с полем *AUTH-TOKEN* куда можно вставить токен нашего пользователя.
Значение которого можно вытащить из таблицы **user**.

Но пока, авторизацию не реализована у нас в API, и хоть swagger радостно сообщит, 
что авторизовался, и будет честно слать токен в заголовке, API его не воспримет.

Давай реализуем необходимый аутентификатор, и все настроим.

Выполним команду и выберем вариант 0 *Empty authenticator*
и дадим название **ApiTokenAuthenticator**
```console
php bin/console make:auth

 What style of authentication do you want? [Empty authenticator]:
  [0] Empty authenticator
  [1] Login form authenticator
 > ApiTokenAuthenticator
```

Теперь, реализуем в нем все необходимые методы:

```php
namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class ApiKeyAuthenticator extends AbstractAuthenticator
{

    protected const HEADER_AUTH_TOKEN = 'AUTH-TOKEN';

    /**
     * @inheritDoc
     */
    public function supports(Request $request): ?bool
    {
        return $request->headers->has(self::HEADER_AUTH_TOKEN);
    }

    /**
     * @inheritDoc
     */
    public function authenticate(Request $request): Passport
    {
        $apiToken = $request->headers->get(self::HEADER_AUTH_TOKEN);

        if (null === $apiToken) {
            throw new CustomUserMessageAuthenticationException('Auth token not found (header: "{{ header }}")', [
                '{{ header }}' => self::HEADER_AUTH_TOKEN,
            ]);
        }

        return new SelfValidatingPassport(new UserBadge($apiToken));
    }

    /**
     * @inheritDoc
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        throw $exception;
    }
}


```


Настроим UserProvider на работу с полем token и настроим firewall 

```yaml
#config/packages/security.yaml

security:
    #....
    providers:
        
        app_user_provider:
            entity:
                class: App\Entity\User
                property: token # <-- тут устанавливаем поле token 
    firewalls:
        dev:
            pattern: ^/(_(profiler|wdt)|css|images|js)/
            security: false
        main:
            lazy: true
            stateless: true  # <-- добавляем stateless: true так как у нас не будут использоваться сессии  
            provider: app_user_provider
            custom_authenticator: App\Security\ApiKeyAuthenticator  # <-- регистрируем ApiKeyAuthenticator

   #...

```

Вот теперь, наш API готов к авторизации пользователя. 
Только в текущий момент, все что мы сделали абсолютно бесполезно, 
так как у нас не настроены доступы.

## Доступы и роли

### Определим роли и разрешения
Давай представим, а как бы хотелось пользоваться текущим API.

1. Метод **POST /api/registration** должен быть доступен всем, чтобы пользователи свободно регистрировались.
2. Должен быть метод **POST /api/login** тоже должен быть доступен всем, и в ответ давать свежий токен для авторизации
3. Методы **GET /api/users и /api/users/{id}** доступно только залогиненым пользователям
4. Методы **PATCH /api/users/{id}** можно менять пароль только себе, доступно только залогиненым пользователям
5. Методы **DELETE /api/users/{id}** можно удалить только себя, доступно только залогиненым пользователям 

Первый пункт, уже реализован. 

Для пункта 2, давай сделаем процессор для метода login и добавим его в операции.

```php
php bin/console make:state-processor UserLoginProcessor
```

Так же добавим метод *findOneByLogin* в **UserRepository**
для нахождения пользователя по email

```php

 public function findOneByLogin($value): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.email = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult();
    }
```

Теперь добавим логику для процессора. 

```php


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

```

Если служба автозапуска и автоконфигурации включена (они включены по умолчанию), то все готово!
```yaml
# api/config/services.yaml
services:
    # default configuration for services in *this* file
    _defaults:
        autowire: true      # Automatically injects dependencies in your services.
        autoconfigure: true # Automatically registers your services as commands, event subscribers, etc.

```

В противном случае, если вы используете пользовательскую конфигурацию внедрения зависимостей, вам необходимо зарегистрировать соответствующую службу и добавить тег api_platform.state_processor.

```yaml
# api/config/services.yaml
services:
    # ...
    App\State\UserLoginProcessor: ~
    # Uncomment only if autoconfiguration is disabled
    #tags: [ 'api_platform.state_processor' ]
```

Осталось создать новый метод для логина и настроить все доступы, как описано в пунктах 3-5

### Настроим доступы

Для удовлетворения требований пункта 2,
переходим к сущности *User* и добавим операцию для метода **POST /api/login**

```php
use App\State\UserLoginProcessor;

...

#[ApiResource(
    normalizationContext: ['groups' => ['user:read']],
    denormalizationContext: ['groups' => ['user:create']],
    operations: [
        new GetCollection(),
        new Post(processor: UserPasswordHasherProcessor::class, uriTemplate: '/registration'),
        new Post(processor: UserLoginProcessor::class,
            uriTemplate: '/login'
        ),
        new Get(),
        new Patch(),
        new Delete(),
    ]
)]
```
Если взглянуть на swagger, то появился новый метод, который уже работает.

Теперь для пункта 3, наших требований, добавим параметр *security* 
для операций **Get() и GetCollection()**

```php
use App\State\UserLoginProcessor;

...

#[ApiResource(
    normalizationContext: ['groups' => ['user:read']],
    denormalizationContext: ['groups' => ['user:create']],
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(processor: UserPasswordHasherProcessor::class, uriTemplate: '/registration'),
        new Post(processor: UserLoginProcessor::class,
            uriTemplate: '/login'
        ),
        new Get(security: "is_granted('ROLE_USER')"),
        new Patch(),
        new Delete(),
    ]
)]
```

Теперь, если вызвать эти операции через swagger, без установленного токена, 
получим ошибку **403 Доступ запрещен**


И теперь, самое интересное - пункты 4 и 5.


```php
use App\State\UserLoginProcessor;

...

#[ApiResource(
    normalizationContext: ['groups' => ['user:read']],
    denormalizationContext: ['groups' => ['user:create']],
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(processor: UserPasswordHasherProcessor::class, uriTemplate: '/registration'),
        new Post(processor: UserLoginProcessor::class,
            uriTemplate: '/login'
        ),
        new Get(security: "is_granted('ROLE_USER')"),
        new Patch(security: "object == user",
            securityMessage: 'Пароль можно менять только себе'),
        new Delete(security: "object == user",
            securityMessage: 'Удалять можно только себя'),
    ]
)]
```


Доступными переменными являются:

`user`: текущий объект, вошедший в систему
`object`: текущий класс ресурсов во время денормализации, текущий ресурс во время нормализации или коллекция ресурсов
`previous_object`: (только после `securityPostDenormalize`) клон объекта, до внесения изменений - это значение равно `null` для операций создания нового объекта
`request`  (только на уровне ресурсов): текущий запрос


Проверки контроля доступа в атрибуте безопасности всегда
выполняются перед этапом денормализации.
Это означает, что для запросов PUT или PATCH объект содержит не значение,
отправленное пользователем, а значения, хранящиеся в данный момент на уровне сохраняемости (persistence layer).



И ещё один момент - для операции **Patch()** 
сейчас отображаются поля
```json
{
  "email": "string",
  "password": "string"
}
```
А хотелось бы настроить поля так, чтобы менять можно было только пароль.

И в результат, выдавать не token, а например email.
Для таких целей, можно настроить группы на уровне операции и добавить их на соответствующие поля в сущности.  

```php
use App\State\UserLoginProcessor;

...

#[ApiResource(
    normalizationContext: ['groups' => ['user:read']],
    denormalizationContext: ['groups' => ['user:create']],
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(processor: UserPasswordHasherProcessor::class, uriTemplate: '/registration'),
        new Post(processor: UserLoginProcessor::class,
            uriTemplate: '/login'
        ),
        new Get(security: "is_granted('ROLE_USER')"),
        new Patch(security: "object == user",
            securityMessage: 'Пароль можно менять только себе',
            normalizationContext: ['groups' => ['user:passwordRead']],
            denormalizationContext: ['groups' => ['user:passwordUpdate']],
            ),
        new Delete(security: "object == user",
            securityMessage: 'Удалять можно только себя'),
    ]
)]
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Groups(['user:create', 'user:passwordRead'])]
    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 255)]
    #[Groups(['user:read'])]
    private ?string $token = null;


    #[Groups(['user:create', 'user:passwordUpdate'])]
    #[SerializedName('password')]
    private $plainPassword;

```

Вот так, у нас получился гибкий функционал. И, обрати внимание как мало кода было написано!
В следующих материалах, расскажу про другие фичи этой библиотеки.

Надеюсь всё было понятно и полезно, и данный материал поможет создавать классные сервисы быстро и безопасно.

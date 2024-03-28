<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\LoginAttemptService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\HttpClientInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

use Nelmio\ApiDocBundle\Annotation\Model;
use OpenApi\Annotations as OA;

class SecurityController extends AbstractController
{
    private $entityManager;
    private $passwordEncoder;
    private $validator;
    private $JWTManager;
    private $security;


    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordEncoder,
        ValidatorInterface $validator,
        JWTTokenManagerInterface $jwtManager,
        Security $security
    )
    {
        $this->entityManager = $entityManager;
        $this->passwordEncoder = $passwordEncoder;
        $this->validator = $validator;
        $this->JWTManager = $jwtManager;
        $this->security = $security;

    }

    /**
     * @Route("/api/register", name="register", methods={"POST"})
     * @OA\Post(
     *     path="/api/register",
     *     tags={"Registration"},
     *     summary="Register a new user",
     *     description="Register a new user",
     *     operationId="register",
     *     @OA\RequestBody(
     *         description="User to register",
     *         required=true,
     *         @OA\JsonContent(
     *        type="object",
     *         required={"email", "password","username"},
     *              @OA\Property(property="email", type="string"),
     *              @OA\Property(property="username", type="string"),
     *              @OA\Property(property="password", type="string")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="User registered successfully"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input"
     *     )
     *   )
     * )
     */
    public function register(Request $request): Response
    {
        try {
            $data = json_decode($request->getContent(), true);

            // Check if a user with the same email already exists
            $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
            if ($existingUser) {
                return new Response('A user with this email already exists', 400);
            }

            $user = new User();
            $user->setEmail($data['email']);
            $user->setUsername($data['username']);
            $user->setRoles(['ROLE_USER']);
            $user->setUsername($data['username']);

            $user->setPassword($this->passwordEncoder->hashPassword($user, $data['password'])); // Correct method

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return new Response('User registered successfully', 201);
        } catch (\Exception $e) {
            return new Response('An error occurred: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @Route("/api/login", name="get_login", methods={"POST"})
     * @OA\Post(
     *     path="/api/login",
     *     tags={"Login"},
     *     summary="Log in a user",
     *     description="Log in a user",
     *     operationId="login",
     *     @OA\RequestBody(
     *         description="User credentials",
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="email", type="string"),
     *             @OA\Property(property="password", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User logged in successfully"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid credentials"
     *     )
     * )
     */
    public function login(
        Request $request,
        RateLimiterFactory $anonymousApiLimiter
    ): Response
    {
        $limiter = $anonymousApiLimiter->create($request->getClientIp());
        $data = json_decode($request->getContent(), true);
        // Si le nombre de tentatives est dépassé
        if ($limiter->consume()->isAccepted() === false) {
            throw new TooManyRequestsHttpException(10,  'You have reached the maximum number of login attempts');
        }
        // Vérfier que l'email et le mot de passe sont bien envoyés
        if (!isset($data['email']) || !isset($data['password'])) {
            return new Response('Email or password not sent', 400);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
        if (!$user || !$this->passwordEncoder->isPasswordValid($user, $data['password'])) {
            return new Response('Invalid credentials', 400);
        }

        $token = $this->JWTManager->create($user);

        return new Response(json_encode(['token' => $token]), 200, ['Content-Type' => 'application/json']);
    }

    /**
     * @Route("/api/login_check", name="api_check_login", methods={"GET"})
     * @OA\Get(
     *     path="/api/login_check",
     *     tags={"Login"},
     *     summary="Check if a user is logged in",
     *     description="Check if a user is logged in",
     *     operationId="check_login",
     *     security={{"bearer":{}}},
     *     @OA\Response(
     *     response=200,
     *     description="User logged in"
     *    ),
     *     @OA\Response(
     *     response=401,
     *     description="User not logged in"
     *   )
     * )
     */
    public function checkLogin(Request $request, HttpClientInterface $client): Response
    {
        $token = $request->headers->get('Authorization');
        $response = $client->request('GET', 'http://localhost:8000/api/login_check', [
            'headers' => [
                'Authorization' => $token
            ]
        ]);

        return new Response($response->getContent(), $response->getStatusCode(), ['Content-Type' => 'application/json']);
    }
}

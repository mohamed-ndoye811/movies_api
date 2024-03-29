<?php

namespace App\Controller;

use App\Entity\Cinema;
use App\Message\ReservationMailNotification;
use Hateoas\Representation\CollectionRepresentation;
use JMS\Serializer\DeserializationContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\SerializerInterface as Nserializer;
use Symfony\Component\HttpFoundation\Response;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Validator\Constraints\Json;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Exception\ValidationException;
use Symfony\Component\Messenger\MessageBusInterface;


use Nelmio\ApiDocBundle\Annotation\Model;
use OpenApi\Annotations as OA;
use Symfony\Component\Uid\UuidV4;

class CinemaController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    // This route is for getting a list of all cinemas
    #[Route('api/cinema', name: 'cinema_listing', methods: ['GET'])]
    /**
     * @OA\Response(
     *     response=200,
     *     description="Returns the list of cinemas",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=cinema::class, groups={"cinema"}))
     *     )
     * )
     * @OA\Tag(name="Cinema")
     */
    public function list(SerializerInterface $serializer, Request $request, MessageBusInterface $bus): Response
    {
        $bus->dispatch(new ReservationMailNotification('Hello Rabbitmq!'));
        $cinemas = $this->entityManager->getRepository(Cinema::class)->findAllCinemas(
            $request->query->get('page', 1),
            $request->query->get('pageSize', 10)
        );

        if ($cinemas && !count($cinemas)) {
            return new JsonResponse("Aucun résultat", 204);
        }

        return $this->apiResponse(
            $serializer,
            $cinemas,
            $request->getAcceptableContentTypes(),
            '200',
            ['cinema']
        );
    }

    // This route is for getting a specific cinema by ID
    #[Route('api/cinema/{uid}', name: 'get_cinema', methods: ['GET'])]
    /**
     * @OA\Response(
     *     response=200,
     *     description="Returns a cinema by ID",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=Cinema::class, groups={"cinema"}))
     *     )
     * )
     * @OA\Tag(name="Cinema")
     */
    public function getCinema(string $uid, SerializerInterface $serializer, Request $request): Response
    {
        $cinema = $this->entityManager->getRepository(Cinema::class)->find($uid);

        if (!$cinema) {
            return $this->json(['message' => 'Cinéma non trouvé'], 404);
        }

        return $this->apiResponse($serializer, $cinema, $request->getAcceptableContentTypes()[0], 200,
            ['cinema']);
    }

    // This route is for getting a list of all cinemas
    #[Route('api/cinema', name: 'create_cinema', methods: ['POST'])]
    /**
     * @OA\Response(
     *     response=201,
     *     description="Add a cinema",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=cinema::class, groups={"cinema"}))
     *     )
     * )
     * @OA\Tag(name="Cinema")
     */
    public function add(SerializerInterface $serializer, Request $request, ValidatorInterface $validator): Response
    {

        $cinema = $serializer->deserialize($request->getContent(), Cinema::class, "json");

        $errors = $validator->validate($cinema);
        if ($errors->count() > 0) {
            return $this->apiResponse(
                $serializer,
                [
                    "status" => 422,
                    "message" => "Le contenu de l'objet cinema dans le body est invalide"
                ],
                $request->getAcceptableContentTypes(),
                '422',
                ['cinema']
            );
        }

        $this->entityManager->persist($cinema);

        $this->entityManager->flush();

        return $this->apiResponse(
            $serializer,
            $cinema,
            $request->getAcceptableContentTypes(),
            201,
            ['cinema']
        );
    }

    // This route is for getting a list of all cinemas
    #[Route('api/cinema/{uid}', name: 'edit_cinema', methods: ['PUT'])]
    /**
     * @OA\Response(
     *     response=200,
     *     description="Edit a cinema",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=cinema::class, groups={"cinema"}))
     *     )
     * )
     * @OA\Tag(name="Cinema")
     */
    public function edit(SerializerInterface $serializer, Nserializer $nserializer, Cinema $cinema, Request $request, ValidatorInterface $validator): Response
    {

        $nserializer->deserialize($request->getContent(), Cinema::class, 'json', [
            AbstractNormalizer::OBJECT_TO_POPULATE => $cinema
        ]);

        $errors = $validator->validate($cinema);
        if ($errors->count() > 0) {
            return $this->apiResponse(
                $serializer,
                [
                    "status" => 422,
                    "message" => "Le contenu de l'objet cinema dans le body est invalide"
                ],
                $request->getAcceptableContentTypes(),
                '422',
                ['cinema']
            );
        }

        $this->entityManager->persist($cinema);

        $this->entityManager->flush();

        return $this->apiResponse(
            $serializer,
            [
                'message' => "Le cinéma est mis à jour avec succès"
            ],
            $request->getAcceptableContentTypes(),
            200,
            ['cinema']
        );
    }


    // This route is for deleting an existing cinema by ID
    #[Route('api/cinema/{uid}', name: 'delete_cinema', methods: ['DELETE'])]
    /**
     * @OA\Response(
     *     response=200,
     *     description="Le cinema a été supprimé avec succès",
     *     @OA\JsonContent(ref=@Model(type=cinema::class, groups={"cinema"}))
     * )
     * @OA\Response(
     *     response=404,
     *     description="Le cinema est inconnu"
     * )
     * @OA\Tag(name="Cinema")
     */
    public function delete(string $uid, SerializerInterface $serializer, Request $request): Response
    {
        $cinema = $this->entityManager->getRepository(Cinema::class)->find($uid);

        if (!$cinema) {
            return new JsonResponse("Cinema non trouvé", 404);
        }

        $this->entityManager->remove($cinema);
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    // this function is to return a response in JSON or XML format
    public function apiResponse(SerializerInterface $serializer, $data, $format, $statusCode, $groups = null): Response
    {
        $xmlMime = 'application/xml';
        $context = SerializationContext::create()->setGroups($groups);
        $contentType = $format == $xmlMime ? $xmlMime : 'application/json';
        $format = $contentType == $xmlMime ? 'xml' : 'json';

        $responseContent = $serializer->serialize($data, $format, $context);
        $response = new Response($responseContent, $statusCode);
        $response->headers->set('Content-Type', $contentType);

        return $response;
    }
}

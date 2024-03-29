<?php

namespace App\Controller;

use App\Entity\Cinema;
use App\Entity\Room;
use Hateoas\Representation\CollectionRepresentation;
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


use Nelmio\ApiDocBundle\Annotation\Model;
use OpenApi\Annotations as OA;
use Symfony\Component\Uid\UuidV4;

class RoomController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    // This route is for getting a list of all rooms
    #[Route('api/cinema/{cinemaUid}/rooms', name: 'room_listing', methods: ['GET'])]
    /**
     * @OA\Response(
     *     response=200,
     *     description="Returns the list of rooms",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=room::class, groups={"room"}))
     *     )
     * )
     * @OA\Tag(name="Room")
     */
    public function list(UuidV4 $cinemaUid, SerializerInterface $serializer, Request $request): Response
    {
        $rooms = $this->entityManager->getRepository(Cinema::class)->find($cinemaUid)->getRooms();

        if($rooms && !count($rooms)) {
            return new JsonResponse("Aucun résultat", 204);
        }

        return $this->apiResponse(
            $serializer,
            $rooms,
            $request->getAcceptableContentTypes(),
            '200',
            ['room']
        );
    }

    // This route is for getting a specific room by ID
    #[Route('api/cinema/{cinemaUid}/rooms/{uid}', name: 'get_room', methods: ['GET'])]
    /**
     * @OA\Response(
     *     response=200,
     *     description="Returns a room by ID",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=Room::class, groups={"room"}))
     *     )
     * )
     * @OA\Tag(name="Room")
     */
    public function getRoom(UuidV4 $cinemaUid, Room $room, SerializerInterface $serializer, Request $request): Response
    {
        $cinema = $this->entityManager->getRepository(Cinema::class)->find($cinemaUid);

        if (!$cinema) {
            return $this->json(['message' => 'Cinéma non trouvé'], 404);
        }

        return $this->apiResponse($serializer, ['room' => $room], $request->getAcceptableContentTypes()[0], 200,
            ['room']);
    }

    // This route is for getting a list of all rooms
    #[Route('api/cinema/{cinemaUid}/rooms', name: 'create_room', methods: ['POST'])]
    /**
     * @OA\Response(
     *     response=201,
     *     description="Add a room",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=room::class, groups={"room"}))
     *     )
     * )
     * @OA\Tag(name="Room")
     */
    public function add(UuidV4 $cinemaUid, SerializerInterface $serializer, Request $request, ValidatorInterface $validator): Response
    {
        $cinema = $this->entityManager->getRepository(Cinema::class)->find($cinemaUid);
        $room = $serializer->deserialize($request->getContent(), Room::class, "json");


        $errors = $validator->validate($room);
        if ($errors->count() > 0) {
            return $this->apiResponse(
                $serializer,
                [
                    "status" => 422,
                    "message" => "Objet non valide: " . $errors[0]->getMessage()
                ],
                $request->getAcceptableContentTypes(),
                '422',
                ['room']
            );
        }

        $cinema->addRoom($room);

        $this->entityManager->persist($room);
        $this->entityManager->persist($cinema);

        $this->entityManager->flush();

        return $this->apiResponse(
            $serializer,
            $room,
            $request->getAcceptableContentTypes(),
            201,
            ['room']
        );
    }

    // This route is for getting a list of all rooms
    #[Route('api/cinema/{cinemaUid}/rooms/{uid}', name: 'edit_room', methods: ['PUT'])]
    /**
     * @OA\Response(
     *     response=200,
     *     description="Edit a room",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=room::class, groups={"room"}))
     *     )
     * )
     * @OA\Tag(name="Room")
     */
    public function edit(UuidV4 $cinemaUid, UuidV4 $uid, SerializerInterface $serializer, Nserializer $nserializer, Request $request, ValidatorInterface $validator): Response
    {
        $cinema = $this->entityManager->getRepository(Cinema::class)->find($cinemaUid);

        if (!$cinema) {
            return $this->apiResponse($serializer, ['message' => "Cinéma non trouvé"], $request->getAcceptableContentTypes()[0], 404,
                ['room']);
        }

        $room = $cinema->getRoom($uid);

        if (!$room) {
            return $this->apiResponse($serializer, ['message' => "Salle non trouvé"], $request->getAcceptableContentTypes()[0], 404,
                ['room']);
        }

        $nserializer->deserialize($request->getContent(), Room::class, 'json', [
            AbstractNormalizer::OBJECT_TO_POPULATE => $room
        ]);

        $errors = $validator->validate($room);
        if ($errors->count() > 0) {
            return $this->apiResponse(
                $serializer,
                [
                    "status" => 422,
                    "message" => "Objet non valide: " . $errors[0]->getMessage()
                ],
                $request->getAcceptableContentTypes(),
                '422',
                ['room']
            );
        }

        $this->entityManager->persist($room);

        $this->entityManager->flush();

        return $this->apiResponse(
            $serializer,
            $room,
            $request->getAcceptableContentTypes(),
            200,
            ['room']
        );
    }


    // This route is for deleting an existing room by ID
    #[Route('api//cinema/{cinemaUid}/rooms/{uid}', name: 'delete_room', methods: ['DELETE'])]
    /**
     * @OA\Response(
     *     response=204,
     *     description="La salle a été supprimé avec succès",
     *     @OA\JsonContent(ref=@Model(type=room::class, groups={"room"}))
     * )
     * @OA\Response(
     *     response=404,
     *     description="LLa salle n'a pas été trouvée"
     * )
     * @OA\Tag(name="Room")
     */
    public function delete(UuidV4 $cinemaUid, UuidV4 $uid, SerializerInterface $serializer, Request $request): Response
    {

        $cinema = $this->entityManager->getRepository(Cinema::class)->find($cinemaUid);

        if (!$cinema) {
            return $this->apiResponse($serializer, ['message' => "Cinéma non trouvé"], $request->getAcceptableContentTypes()[0], 404,
                ['room']);
        }

        $room = $cinema->getRoom($uid);

        if (!$room) {
            return $this->apiResponse($serializer, ['message' => "Salle non trouvée"], $request->getAcceptableContentTypes()[0], 404,
                ['room']);
        }

        $cinema->removeRoom($room);
        $this->entityManager->remove($room);
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

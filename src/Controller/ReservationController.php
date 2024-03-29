<?php

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\Room;
use App\Entity\Sceance;
use App\Message\ReservationMailNotification;
use Hateoas\Representation\CollectionRepresentation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
 use Symfony\Component\Serializer\SerializerInterface as Nserializer;
use Symfony\Component\HttpFoundation\Response;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Exception\ValidationException;


use Nelmio\ApiDocBundle\Annotation\Model;
use OpenApi\Annotations as OA;
use Symfony\Component\Uid\UuidV4;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ReservationController extends AbstractController
{
    private $entityManager;
    private $httpClient;
    private MessageBusInterface $bus;

    public function __construct(
        EntityManagerInterface $entityManager,
        HttpClientInterface $httpClient,
        MessageBusInterface $bus
    )
    {
        $this->entityManager = $entityManager;
        $this->httpClient = $httpClient;
        $this->bus = $bus;
    }


    #[Route('reservations/{uid}/confirm', name: 'confirm_reservation', methods: ['POST'])]
    /**
     * @OA\Response(
     *     response=201,
     *     description="Réservation effectuée avec succès",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=reservation::class, groups={"reservation"}))
     *     )
     * )
     * @OA\Response(
     *     response=404,
     *     description="Réservation non trouvée"
     * )
     * @OA\Response(
     *     response=422,
     *     description="Le contenu de l'objet reservation dans le body est invalide"
     * )
     * @OA\Response(
     *     response=410,
     *     description="La réservation est expirée"
     * )
     * @OA\Tag(name="reservation")
     */
    public function confirm(string $uid, SerializerInterface $serializer, Request $request): Response
    {
        $reservation = $this->entityManager->getRepository(Reservation::class)->find($uid);

        if (!$reservation) {
            return $this->json(['message' => 'Réservation non trouvée'], 404);
        }

        if ($reservation->getStatus() !== Reservation::STATUS_OPEN) {
            return $this->json(['message' => 'Le contenu de l\'objet reservation dans le body est invalide'], 422);
        }

        if ($reservation->getExpiresAt() < new \DateTimeImmutable('now')) {
            return $this->json(['message' => 'La réservation est expirée'], 410);
        }

        try {
            $reservation->setStatus(Reservation::STATUS_CONFIRMED);
            $this->entityManager->persist($reservation);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            return $this->json(['message' => 'Erreur interne'], 500);
        }

        return $this->json(['message' => 'Réservation effectuée avec succès'], 201);
    }

    #[Route('reservations/{uid}', name: 'get_reservation_details', methods: ['GET'])]
    /**
     * @OA\Response(
     *     response=200,
     *     description="La réservation est affché avec succès",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=Reservation::class, groups={"reservation"}))
     *     )
     * )
     * @OA\Response(
     *     response=404,
     *     description="La réservation est inconnu"
     * )
     * @OA\Response(
     *     response=500,
     *     description="Erreur interne"
     * )
     * @OA\Tag(name="reservation")
     */
    public function getReservationDetails(string $uid, SerializerInterface $serializer, Request $request): Response
    {
        try {
            $reservation = $this->entityManager->getRepository(Reservation::class)->find($uid);

            if (!$reservation) {
                return $this->json(['message' => 'La réservation est inconnu'], 404);
            }

            return $this->apiResponse(
                $serializer,
                ['reservation' => $reservation],
                $request->getAcceptableContentTypes()[0],
                200,
                ['reservation']
            );
        } catch (\Exception $e) {
            return $this->json(['message' => 'Erreur interne'], 500);
        }
    }

    // This route is for getting a list of all reservations
    #[Route('movie/{movieUid}/reservations', name: 'get_movie_reservations', methods: ['GET'])]
    /**
     * @OA\Response(
     *     response=200,
     *     description="Les réservations sont affichés avec succès",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=Reservation::class, groups={"reservation"}))
     *     )
     * )
     * @OA\Response(
     *     response=404,
     *     description="Le film est inconnu"
     * )
     * @OA\Response(
     *     response=500,
     *     description="Erreur interne"
     * )
     * @OA\Tag(name="reservation")
     */
    public function getMovieReservations(string $movieUid, SerializerInterface $serializer, Request $request): Response
    {
        try {
            $response = $this->httpClient->request('GET', 'http://web:8000/api/movies/'.$movieUid);

            if (404 === $response->getStatusCode()) {
                return $this->json(['message' => 'Le film est inconnu'], 404);
            }

            $data = $response->toArray();
            $movie = $data['movie'];
            $movieUid = $movie['uid']; // Récupérez l'identifiant du film
            $reservations = $this->entityManager->getRepository(Reservation::class)->findBy(['movieUid' => $movieUid, 'status' => Reservation::STATUS_OPEN]);

            return $this->apiResponse(
                $serializer,
                $reservations,
                $request->getAcceptableContentTypes(),
                '200',
                ['reservation']
            );
        } catch (\Exception|TransportExceptionInterface $e) {
            dump($e);
            return $this->json(['message' => 'Erreur interne'], 500);
        }
    }

    // This route is for getting a specific reservation by ID
    #[Route('reservation/{uid}', name: 'get_reservation', methods: ['GET'])]
    /**
     * @OA\Response(
     *     response=200,
     *     description="Returns a reservation by ID",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=Reservation::class, groups={"reservation"}))
     *     )
     * )
     * @OA\Tag(name="Reservation")
     */
    public function getReservation(string $uid, SerializerInterface $serializer, Request $request): Response
    {
        $reservation = $this->entityManager->getRepository(Reservation::class)->find($uid);

        if (!$reservation) {
            return $this->json(['message' => 'Cinéma non trouvé'], 404);
        }

        return $this->apiResponse($serializer, ['reservation' => $reservation], $request->getAcceptableContentTypes()[0], 200,
            ['reservation']);
    }

    // This route is for getting a list of all reservations
    #[Route('movie/{movieUid}/reservations', name: 'create_reservation', methods: ['POST'])]
    /**
     * @OA\Response(
     *     response=200,
     *     description="Add a reservation",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=reservation::class, groups={"reservation"}))
     *     )
     * )
     * @OA\Tag(name="reservation")
     */
    public function add(UuidV4 $movieUid, SerializerInterface $serializer, Request $request, ValidatorInterface $validator): Response
    {
        $req = (array) json_decode($request->getContent());
        dump($request->getContent());
        dump($this->entityManager->getRepository(Sceance::class)->find($req['sceance']));
        $sceance = $this->entityManager->getRepository(Sceance::class)->find($req['sceance']);
        $room = $this->entityManager->getRepository(Room::class)->find($req['room']);

        $seatsLeft = $room->getSeats() - $req['nbSeats'];

        if($seatsLeft < 0) {
            return $this->apiResponse(
                $serializer,
                "Plus de place disponible pour cette séance",
                $request->getAcceptableContentTypes(),
                204,
                ['reservation']
            );
        }

        $reservation = new Reservation();
        $reservation->setSeats($req['nbSeats']);
        $reservation->setRank($room->getSeats());
        $reservation->setExpiresAt(\DateTimeImmutable::createFromMutable($sceance->getDate()));
        $reservation->setStatus(Reservation::STATUS_OPEN);


        $errors = $validator->validate($reservation);
        if ($errors->count() > 0) {
            return $this->apiResponse(
                $serializer,
                [
                    "status" => 422,
                    "message" => $errors[0]->getMessage()
                ],
                $request->getAcceptableContentTypes(),
                '422',
                ['reservation']
            );
        }

        $sceance->setMovie($movieUid);
        $room->setSeats($seatsLeft);

        $this->entityManager->persist($reservation);
        $this->entityManager->persist($room);
        $this->entityManager->persist($sceance);

        $this->entityManager->flush();

        $this->bus->dispatch(new ReservationMailNotification($reservation));

        return $this->apiResponse(
            $serializer,
            [
                "reservation" => $reservation,
                "message" => "La reservation a été prise en compte"
            ],
            $request->getAcceptableContentTypes(),
            '201',
            ['reservation']
        );
    }

    // This route is for getting a list of all reservations
    #[Route('reservation/{uid}', name: 'edit_reservation', methods: ['PUT'])]
    /**
     * @OA\Response(
     *     response=200,
     *     description="Edit a reservation",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=reservation::class, groups={"reservation"}))
     *     )
     * )
     * @OA\Tag(name="reservation")
     */
    public function edit(Reservation $reservation, Nserializer $nserializer, ValidatorInterface $validator, SerializerInterface $serializer, Request $request): Response
    {
        $nserializer->deserialize($request->getContent(), Reservation::class, 'json', [
            AbstractNormalizer::OBJECT_TO_POPULATE => $reservation
        ]);

        $errors = $validator->validate($reservation);
        if ($errors->count() > 0) {
            return $this->apiResponse(
                $serializer,
                [
                    "status" => 422,
                    "message" => "Objet non valide: " . $errors[0]->getMessage()
                ],
                $request->getAcceptableContentTypes(),
                '422',
                ['reservation']
            );
        }

        $this->entityManager->persist($reservation);

        $this->entityManager->flush();

        return $this->apiResponse(
            $serializer,
            [
                'message' => "La salle a été mise à jour avec succès"
            ],
            $request->getAcceptableContentTypes(),
            200,
            ['reservation']
        );
    }


    // This route is for deleting an existing reservation by ID
    #[Route('reservation/{uid}', name: 'delete_reservation', methods: ['DELETE'])]
    /**
     * @OA\Response(
     *     response=200,
     *     description="Le reservation a été supprimé avec succès",
     *     @OA\JsonContent(ref=@Model(type=reservation::class, groups={"reservation"}))
     * )
     * @OA\Response(
     *     response=404,
     *     description="Le reservation est inconnu"
     * )
     * @OA\Tag(name="reservation")
     */
    public function delete(string $uid, SerializerInterface $serializer, Request $request): Response
    {
        $reservation = $this->entityManager->getRepository(Reservation::class)->find($uid);

        if ($reservation) {
            $this->entityManager->remove($reservation);
            $this->entityManager->flush();
            $message = 'Le reservation a été supprimé avec succès';
            $statusCode = 200;
        } else {
            $message = 'Le reservation est inconnu';
            $statusCode = 404;
        }

        return $this->apiResponse($serializer, ['message' => $message], $request->getAcceptableContentTypes()[0], $statusCode,
            ['reservation']);
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

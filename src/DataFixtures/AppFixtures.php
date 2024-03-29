<?php

namespace App\DataFixtures;

use App\Entity\Reservation;
use App\Entity\Cinema;
use App\Entity\Room;
use App\Entity\Sceance;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Uid\Uuid;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager)
    {
        // Création d'une instance de Cinema
        $cinema = new Cinema();
        $cinema->setUid(Uuid::v4());
        $cinema->setName('Cinema 1');

        // Création d'une instance de Room
        $room = new Room();
        $room->setUid(Uuid::v4());
        $room->setName('Room 1');
        $room->setSeats(100);
        $room->setCinema($cinema);

        // Création d'une instance de Sceance
        $sceance = new Sceance();
        $sceance->setUid(Uuid::v4());
        $sceance->setMovie(Uuid::v4());
        $sceance->setDate(new \DateTime());
        $sceance->setRoom($room);

        // Création d'une instance de Reservation
        $reservation = new Reservation();
        $reservation->setUid(Uuid::v4());
        $reservation->setMovieUid(Uuid::v4()->toRfc4122());
        $reservation->setRank(1);
        $reservation->setStatus(Reservation::STATUS_OPEN);
        $reservation->setSeats(2);
        $reservation->setExpiresAt(new \DateTimeImmutable('now + 1 day')); // Ajout de la date d'expiration

// Persiste les entités
        $manager->persist($cinema);
        $manager->persist($room);
        $manager->persist($sceance);
        $manager->persist($reservation);

// Flush les données dans la base de données
        $manager->flush();
    }
}

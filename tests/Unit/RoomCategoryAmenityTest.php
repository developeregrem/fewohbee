<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Amenity;
use App\Entity\RoomCategory;
use App\Entity\RoomCategoryImage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Amenity and RoomCategoryImage entities
 * and their relationships with RoomCategory.
 */
final class RoomCategoryAmenityTest extends TestCase
{
    /** Verify that amenities can be added to a RoomCategory via the owning side. */
    public function testAddAmenityToRoomCategory(): void
    {
        $category = new RoomCategory();
        $amenity = $this->createAmenity('wifi', 'room');

        $category->addAmenity($amenity);

        self::assertCount(1, $category->getAmenities());
        self::assertSame($amenity, $category->getAmenities()->first());
    }

    /** Verify that adding the same amenity twice does not create duplicates. */
    public function testAddSameAmenityTwiceIsIdempotent(): void
    {
        $category = new RoomCategory();
        $amenity = $this->createAmenity('wifi', 'room');

        $category->addAmenity($amenity);
        $category->addAmenity($amenity);

        self::assertCount(1, $category->getAmenities());
    }

    /** Verify that amenities can be removed from a RoomCategory. */
    public function testRemoveAmenityFromRoomCategory(): void
    {
        $category = new RoomCategory();
        $amenity = $this->createAmenity('wifi', 'room');

        $category->addAmenity($amenity);
        $category->removeAmenity($amenity);

        self::assertCount(0, $category->getAmenities());
    }

    /** Verify that the inverse side (Amenity → RoomCategory) syncs correctly. */
    public function testAmenityInverseSideSyncsRoomCategory(): void
    {
        $category = new RoomCategory();
        $amenity = $this->createAmenity('parking', 'outdoor');

        $amenity->addRoomCategory($category);

        self::assertCount(1, $category->getAmenities());
        self::assertCount(1, $amenity->getRoomCategories());
    }

    /** Verify that removing via the inverse side cleans up both sides. */
    public function testAmenityRemoveRoomCategorySyncsBothSides(): void
    {
        $category = new RoomCategory();
        $amenity = $this->createAmenity('parking', 'outdoor');

        $amenity->addRoomCategory($category);
        $amenity->removeRoomCategory($category);

        self::assertCount(0, $category->getAmenities());
        self::assertCount(0, $amenity->getRoomCategories());
    }

    /** Verify that images can be added to a RoomCategory. */
    public function testAddImageToRoomCategory(): void
    {
        $category = new RoomCategory();
        $image = new RoomCategoryImage();
        $image->setFilename('test.jpg');

        $category->addImage($image);

        self::assertCount(1, $category->getImages());
        self::assertSame($category, $image->getRoomCategory());
    }

    /** Verify that adding the same image twice is idempotent. */
    public function testAddSameImageTwiceIsIdempotent(): void
    {
        $category = new RoomCategory();
        $image = new RoomCategoryImage();
        $image->setFilename('test.jpg');

        $category->addImage($image);
        $category->addImage($image);

        self::assertCount(1, $category->getImages());
    }

    /** Verify that removing an image works. */
    public function testRemoveImageFromRoomCategory(): void
    {
        $category = new RoomCategory();
        $image = new RoomCategoryImage();
        $image->setFilename('test.jpg');

        $category->addImage($image);
        $category->removeImage($image);

        self::assertCount(0, $category->getImages());
    }

    /** Verify that getPrimaryImage returns the image marked as primary. */
    public function testGetPrimaryImageReturnsPrimaryImage(): void
    {
        $category = new RoomCategory();

        $img1 = new RoomCategoryImage();
        $img1->setFilename('a.jpg');
        $img1->setIsPrimary(false);

        $img2 = new RoomCategoryImage();
        $img2->setFilename('b.jpg');
        $img2->setIsPrimary(true);

        $category->addImage($img1);
        $category->addImage($img2);

        self::assertSame($img2, $category->getPrimaryImage());
    }

    /** Verify that getPrimaryImage falls back to the first image if none is primary. */
    public function testGetPrimaryImageFallsBackToFirstImage(): void
    {
        $category = new RoomCategory();

        $img1 = new RoomCategoryImage();
        $img1->setFilename('a.jpg');
        $img1->setIsPrimary(false);

        $img2 = new RoomCategoryImage();
        $img2->setFilename('b.jpg');
        $img2->setIsPrimary(false);

        $category->addImage($img1);
        $category->addImage($img2);

        self::assertSame($img1, $category->getPrimaryImage());
    }

    /** Verify that getPrimaryImage returns null when there are no images. */
    public function testGetPrimaryImageReturnsNullWhenEmpty(): void
    {
        $category = new RoomCategory();

        self::assertNull($category->getPrimaryImage());
    }

    /** Verify all Amenity getters/setters work correctly. */
    public function testAmenityGettersAndSetters(): void
    {
        $amenity = new Amenity();
        $amenity->setSlug('wifi');
        $amenity->setIconFaClass('fa-solid fa-wifi');
        $amenity->setCategory('room');
        $amenity->setSortOrder(5);
        $amenity->setBookingComRmaCode('RMA-123');
        $amenity->setAirbnbAmenityId('AIRBNB-456');

        self::assertSame('wifi', $amenity->getSlug());
        self::assertSame('fa-solid fa-wifi', $amenity->getIconFaClass());
        self::assertSame('room', $amenity->getCategory());
        self::assertSame(5, $amenity->getSortOrder());
        self::assertSame('RMA-123', $amenity->getBookingComRmaCode());
        self::assertSame('AIRBNB-456', $amenity->getAirbnbAmenityId());
        self::assertSame('wifi', (string) $amenity);
    }

    /** Verify all RoomCategoryImage getters/setters work correctly. */
    public function testRoomCategoryImageGettersAndSetters(): void
    {
        $category = new RoomCategory();
        $image = new RoomCategoryImage();

        $image->setRoomCategory($category);
        $image->setFilename('photo.jpg');
        $image->setSortOrder(3);
        $image->setIsPrimary(true);
        $image->setTag('room');

        self::assertSame($category, $image->getRoomCategory());
        self::assertSame('photo.jpg', $image->getFilename());
        self::assertSame(3, $image->getSortOrder());
        self::assertTrue($image->isPrimary());
        self::assertSame('room', $image->getTag());
    }

    /** Verify that OTA fields default to null. */
    public function testOtaFieldsDefaultToNull(): void
    {
        $amenity = new Amenity();
        $amenity->setSlug('test');
        $amenity->setIconFaClass('fa-solid fa-check');
        $amenity->setCategory('room');

        self::assertNull($amenity->getBookingComRmaCode());
        self::assertNull($amenity->getAirbnbAmenityId());

        $image = new RoomCategoryImage();
        self::assertNull($image->getTag());
        self::assertNull($image->getId());
    }

    private function createAmenity(string $slug, string $category): Amenity
    {
        $amenity = new Amenity();
        $amenity->setSlug($slug);
        $amenity->setIconFaClass('fa-solid fa-check');
        $amenity->setCategory($category);

        return $amenity;
    }
}

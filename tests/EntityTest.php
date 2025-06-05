<?php

declare(strict_types=1);

namespace Tests;

use App\Entity;
use PHPUnit\Framework\TestCase;

// Concrete Entity for testing
class TestEntity extends Entity
{
    public function getTableName(): string
    {
        return 'test_table';
    }

    public function getPrimaryKeyName(): string
    {
        return 'id';
    }
}

class EntityTest extends TestCase
{
    public function testConstructorAndPropertyPopulation()
    {
        $data = ['id' => 1, 'name' => 'Test Name'];
        $entity = new TestEntity($data);

        $this->assertSame(1, $entity->id, "Getter should retrieve initial id.");
        $this->assertSame('Test Name', $entity->name, "Getter should retrieve initial name.");
        $this->assertNull($entity->non_existent_prop, "Getter should return null for non-existent prop.");
    }

    public function testMagicGetAndSet()
    {
        $entity = new TestEntity();

        // Test __set
        $entity->name = 'New Name';
        $entity->age = 30;

        // Test __get
        $this->assertSame('New Name', $entity->name, "__get should retrieve value set by __set.");
        $this->assertSame(30, $entity->age, "__get should retrieve value set by __set.");
    }

    public function testIsDirtyAndGetDirtyData()
    {
        $initialData = ['id' => 1, 'name' => 'Initial Name'];
        $entity = new TestEntity($initialData);

        $this->assertFalse($entity->isDirty(), "Entity should not be dirty after initialization.");
        $this->assertEmpty($entity->getDirtyData(), "Dirty data should be empty after initialization.");

        // Modify a property
        $entity->name = 'Updated Name';
        $this->assertTrue($entity->isDirty(), "Entity should be dirty after property modification.");
        $this->assertSame(['name' => 'Updated Name'], $entity->getDirtyData(), "Dirty data should contain the modified property.");

        // Modify another property
        $entity->status = 'active';
        $this->assertTrue($entity->isDirty(), "Entity should still be dirty.");
        $this->assertSame(
            ['name' => 'Updated Name', 'status' => 'active'],
            $entity->getDirtyData(),
            "Dirty data should contain all modified properties."
        );

        // Set property back to original value
        $entity->name = 'Initial Name';
        $this->assertTrue($entity->isDirty(), "Entity should still be dirty if other props changed.");
        $this->assertSame(
            ['status' => 'active'], // 'name' is no longer dirty as it's back to original
            $entity->getDirtyData(),
            "Dirty data should not contain property set back to original value if it was part of initial data."
        );
         // Set a property that was not initially set, then set it back to what it was just set to (should not be dirty)
        $entity->new_prop = "new_val";
        $this->assertSame(
            ['status' => 'active', 'new_prop' => 'new_val'],
            $entity->getDirtyData(),
            "Dirty data should contain new_prop."
        );
        $entity->new_prop = "new_val"; // Setting to same value
         $this->assertSame(
            ['status' => 'active', 'new_prop' => 'new_val'], // Should remain unchanged
            $entity->getDirtyData(),
            "Setting to same value should not change dirty status for new_prop."
        );


    }

    public function testMarkAsPristine()
    {
        $initialData = ['id' => 1, 'name' => 'Initial Name'];
        $entity = new TestEntity($initialData);

        $entity->name = 'Updated Name';
        $entity->status = 'active';

        $this->assertTrue($entity->isDirty());
        $this->assertNotEmpty($entity->getDirtyData());

        $entity->markAsPristine();

        $this->assertFalse($entity->isDirty(), "Entity should not be dirty after markAsPristine.");
        $this->assertEmpty($entity->getDirtyData(), "Dirty data should be empty after markAsPristine.");

        // Verify that data was updated
        $this->assertSame('Updated Name', $entity->name, "Data should be updated after markAsPristine.");
        $this->assertSame('active', $entity->status, "New data should be present after markAsPristine.");
    }

    public function testSettingPropertyToSameOriginalValue()
    {
        $initialData = ['id' => 1, 'name' => 'Initial Name'];
        $entity = new TestEntity($initialData);

        $this->assertFalse($entity->isDirty(), "Entity should not be dirty initially.");

        $entity->name = 'Initial Name'; // Setting to the same value it already has

        $this->assertFalse($entity->isDirty(), "Entity should not be dirty after setting a property to its original value.");
        $this->assertEmpty($entity->getDirtyData(), "Dirty data should be empty.");
    }

    public function testNewPropertyThenSetBackToOriginalLikeValue()
    {
        $entity = new TestEntity(['id' => 1]);
        $entity->name = 'First Set';
        $this->assertTrue($entity->isDirty());
        $this->assertSame(['name' => 'First Set'], $entity->getDirtyData());

        // This behavior is a bit nuanced. If 'name' was not in original $this->data,
        // then setting it to something, then setting it again to something else,
        // it's always "dirty" relative to its non-existence in $this->data.
        // If we set it to a value, then markAsPristine, then set it again.
        $entity->markAsPristine();
        $this->assertFalse($entity->isDirty()); // Now 'First Set' is the "original" data for 'name'

        $entity->name = 'Second Set';
        $this->assertTrue($entity->isDirty());
        $this->assertSame(['name' => 'Second Set'], $entity->getDirtyData());

        $entity->name = 'First Set'; // Set back to the "pristine" value for 'name'
        $this->assertFalse($entity->isDirty(), "Entity should not be dirty if property is set back to its now pristine value.");
        $this->assertEmpty($entity->getDirtyData(), "Dirty data should be empty.");
    }
}

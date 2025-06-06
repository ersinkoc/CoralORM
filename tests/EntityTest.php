<?php

declare(strict_types=1);

namespace Tests\YourOrm;

use YourOrm\Entity;
use PHPUnit\Framework\TestCase;

use YourOrm\Mapping\Table;
use YourOrm\Mapping\Column;
use YourOrm\Mapping\PrimaryKey;
use YourOrm\Mapping\CreatedAt;
use YourOrm\Mapping\UpdatedAt;
use YourOrm\Mapping\NotNull;
use YourOrm\Mapping\Length;
use DateTimeImmutable;
use Tests\YourOrm\TestEntities\UserWithHasMany;
use Tests\YourOrm\TestEntities\PostWithBelongsTo;
use Tests\YourOrm\TestEntities\UserWithHasManyDefaultLocalKey;

// Attribute-based Test Entity
#[Table(name: 'test_entities')]
class TestAttributeEntity extends Entity // YourOrm\Entity
{
    #[PrimaryKey]
    #[Column(name: 'entity_id', type: 'int')]
    public ?int $id = null;

    #[Column(name: 'entity_name', type: 'string')]
    #[NotNull]
    public ?string $name = null;

    #[Column(name: 'description', type: 'string')]
    #[Length(max: 255)]
    public ?string $description = null;

    #[Column(name: 'code', type: 'string')]
    #[Length(min: 5, max: 10)]
    public ?string $code = null;

    #[Column(type: 'int')] // DB name will be 'age_value' by default snake_case
    public ?int $ageValue = null;

    #[Column(type: 'bool')]
    public ?bool $isActive = null;

    #[Column(type: 'DateTimeImmutable')]
    public ?DateTimeImmutable $registeredAt = null;

    #[Column(type: 'array')]
    public ?array $settings = null;

    #[CreatedAt]
    #[Column(name: 'created_on', type: 'DateTimeImmutable')] // Explicit name for timestamp column
    public ?DateTimeImmutable $createdOn = null;

    #[UpdatedAt]
    #[Column(name: 'updated_on', type: 'DateTimeImmutable')] // Explicit name for timestamp column
    public ?DateTimeImmutable $updatedOn = null;

    // Property without Column attribute, should not be in metadata->columns unless it's PK/timestamp fallback
    public string $unmappedProperty = 'default value';

    // The old TestEntity class definition should be removed.
}


class EntityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear the static cache in Entity class before each test
        $entityMetadataCacheReflector = new \ReflectionProperty(\YourOrm\Entity::class, 'entityMetadataCache');
        $entityMetadataCacheReflector->setAccessible(true);
        $entityMetadataCacheReflector->setValue(null, []);
    }

    // Test metadata parsing
    public function testMetadataParsing()
    {
        $metadata = TestAttributeEntity::getMetadata();

        $this->assertEquals('test_entities', $metadata->tableName);
        $this->assertEquals('id', $metadata->primaryKeyProperty);
        $this->assertEquals('entity_id', $metadata->primaryKeyColumn);
        $this->assertTrue($metadata->isPrimaryKeyAutoIncrement);

        $this->assertArrayHasKey('name', $metadata->columns);
        $this->assertEquals('entity_name', $metadata->columns['name']['name']);
        $this->assertEquals('string', $metadata->columns['name']['phpType']);
        $this->assertArrayHasKey('name', $metadata->propertyValidations, "Validation metadata should exist for 'name'.");
        $this->assertInstanceOf(NotNull::class, $metadata->propertyValidations['name'][0]);

        $this->assertArrayHasKey('description', $metadata->columns);
        $this->assertEquals('description', $metadata->columns['description']['name']);
        $this->assertArrayHasKey('description', $metadata->propertyValidations, "Validation metadata should exist for 'description'.");
        $this->assertInstanceOf(Length::class, $metadata->propertyValidations['description'][0]);
        $this->assertEquals(255, $metadata->propertyValidations['description'][0]->max);
        $this->assertNull($metadata->propertyValidations['description'][0]->min);

        $this->assertArrayHasKey('code', $metadata->columns);
        $this->assertEquals('code', $metadata->columns['code']['name']);
        $this->assertArrayHasKey('code', $metadata->propertyValidations, "Validation metadata should exist for 'code'.");
        $this->assertInstanceOf(Length::class, $metadata->propertyValidations['code'][0]);
        $this->assertEquals(5, $metadata->propertyValidations['code'][0]->min);
        $this->assertEquals(10, $metadata->propertyValidations['code'][0]->max);


        $this->assertArrayHasKey('ageValue', $metadata->columns);
        $this->assertEquals('age_value', $metadata->columns['ageValue']['name']); // Default snake case
        $this->assertEquals('int', $metadata->columns['ageValue']['phpType']);

        $this->assertEquals('createdOn', $metadata->createdAtProperty);
        $this->assertEquals('created_on', $metadata->createdAtColumn);
        $this->assertEquals('updatedOn', $metadata->updatedAtProperty);
        $this->assertEquals('updated_on', $metadata->updatedAtColumn);

        $this->assertArrayNotHasKey('unmappedProperty', $metadata->columns, "Unmapped property should not be in columns metadata.");

        // Test caching - get metadata again, should return same instance
        $metadata2 = TestAttributeEntity::getMetadata();
        $this->assertSame($metadata, $metadata2, "Metadata should be cached.");
    }

    public function testConstructorWithAttributeMappingAndCasting()
    {
        // Data keys are DB column names
        $data = [
            'entity_id' => '1',
            'entity_name' => 'Test Name from DB',
            'age_value' => '30',
            'is_active' => '1',
            'registered_at' => '2023-01-05 10:00:00',
            'settings' => '{"theme":"dark"}',
        ];
        $entity = new TestAttributeEntity($data);

        $this->assertSame(1, $entity->id, "Constructor should cast ID to int.");
        $this->assertSame('Test Name from DB', $entity->name);
        $this->assertSame(30, $entity->ageValue, "Constructor should cast age to int.");
        $this->assertTrue($entity->isActive, "Constructor should cast isActive to bool.");
        $this->assertInstanceOf(DateTimeImmutable::class, $entity->registeredAt);
        if ($entity->registeredAt) { // Check to satisfy static analyzer
            $this->assertEquals('2023-01-05 10:00:00', $entity->registeredAt->format('Y-m-d H:i:s'));
        }
        $this->assertEquals(['theme' => 'dark'], $entity->settings);

        $this->assertFalse($entity->isDirty(), "Entity should be pristine after construction with data.");
    }

    public function testSetAndGetWithAttributeCasting()
    {
        $entity = new TestAttributeEntity();

        $entity->id = '123'; // Set string, should be cast to int
        $this->assertSame(123, $entity->id, "__set should cast to int, __get should return int.");

        $entity->name = 12345; // Set int, should be cast to string
        $this->assertSame('12345', $entity->name);

        $entity->isActive = 'false'; // Set string 'false', should be cast to bool false
        $this->assertFalse($entity->isActive);

        $entity->isActive = 1; // Set int 1, should be cast to bool true
        $this->assertTrue($entity->isActive);

        $dateStr = '2023-03-15 12:30:00';
        $entity->registeredAt = $dateStr;
        $this->assertInstanceOf(DateTimeImmutable::class, $entity->registeredAt);
        if($entity->registeredAt){
            $this->assertEquals($dateStr, $entity->registeredAt->format('Y-m-d H:i:s'));
        }

        $settingsArray = ['mode' => 'eco', 'level' => 5];
        $entity->settings = $settingsArray; // Set array
        $this->assertEquals($settingsArray, $entity->settings); // Get array

        $this->assertTrue($entity->isDirty());
        $dirty = $entity->getDirtyProperties();
        $this->assertSame(123, $dirty['id']);
        $this->assertSame('12345', $dirty['name']);
        $this->assertTrue($dirty['isActive']);
        $this->assertInstanceOf(DateTimeImmutable::class, $dirty['registeredAt']);
        $this->assertEquals($settingsArray, $dirty['settings']);
    }

    public function testTimestamps()
    {
        $entity = new TestAttributeEntity();
        $this->assertNull($entity->createdOn);
        $this->assertNull($entity->updatedOn);

        $entity->touchTimestamps(true); // isNew = true
        $this->assertInstanceOf(DateTimeImmutable::class, $entity->createdOn);
        $this->assertInstanceOf(DateTimeImmutable::class, $entity->updatedOn);
        $createdAtBefore = $entity->createdOn;
        $updatedAtBefore = $entity->updatedOn;

        usleep(5000); // Sleep for 5 milliseconds to ensure timestamp changes if system clock is fast

        $entity->touchTimestamps(false); // isNew = false
        $this->assertSame($createdAtBefore, $entity->createdOn, "CreatedAt should not change on subsequent touch.");
        $this->assertNotEquals($updatedAtBefore, $entity->updatedOn, "UpdatedAt should change.");
    }

    public function testToArrayAndPersistenceData()
    {
        $entity = new TestAttributeEntity();
        $entity->id = 1;
        $entity->name = 'Test User';
        $entity->ageValue = 25;
        $entity->isActive = true;
        $entity->registeredAt = new DateTimeImmutable('2023-01-01 00:00:00');
        $entity->settings = ['notify' => true];
        $entity->touchTimestamps(true);
        $entity->markAsPristine();

        $arrayData = $entity->toArray();
        $this->assertEquals(1, $arrayData['entity_id']);
        $this->assertEquals('Test User', $arrayData['entity_name']);
        $this->assertEquals(25, $arrayData['age_value']);
        $this->assertEquals(true, $arrayData['is_active']);
        $this->assertEquals('2023-01-01 00:00:00', $arrayData['registered_at']);
        $this->assertEquals(['notify' => true], $arrayData['settings']);
        $this->assertArrayHasKey('created_on', $arrayData);
        $this->assertArrayHasKey('updated_on', $arrayData);

        $persistenceData = $entity->getAllDataForPersistence();
        $this->assertEquals(1, $persistenceData['entity_id']);
        $this->assertEquals('Test User', $persistenceData['entity_name']);
        $this->assertEquals(25, $persistenceData['age_value']);
        $this->assertEquals(1, $persistenceData['is_active']);
        $this->assertEquals('2023-01-01 00:00:00', $persistenceData['registered_at']);
        $this->assertEquals('{"notify":true}', $persistenceData['settings']);
        $this->assertIsString($persistenceData['created_on']);
        $this->assertIsString($persistenceData['updated_on']);

        $entity->name = 'Updated Name';
        $entity->settings = ['notify' => false, 'level' => 'admin'];
        $dirtyPersistence = $entity->getDirtyDataForPersistence();
        $this->assertCount(2, $dirtyPersistence);
        $this->assertEquals('Updated Name', $dirtyPersistence['entity_name']);
        $this->assertEquals('{"notify":false,"level":"admin"}', $dirtyPersistence['settings']);
    }

    public function testDeprecatedMethodsUseMetadata()
    {
        $entity = new TestAttributeEntity();
        $this->assertEquals('test_entities', $entity->getTableName());
        $this->assertEquals('entity_id', $entity->getPrimaryKeyName());
    }

    // Adapted testIsDirtyAndGetDirtyPropertiesWithAttributes
    public function testIsDirtyAndGetDirtyPropertiesWithAttributes()
    {
        // Constructor expects DB column names
        $initialData = ['entity_id' => 1, 'entity_name' => 'Initial Name'];
        $entity = new TestAttributeEntity($initialData);

        $this->assertFalse($entity->isDirty(), "Entity should not be dirty after initialization with data.");
        // getDirtyData() was removed, use getDirtyProperties()
        $this->assertEmpty($entity->getDirtyProperties(), "Dirty properties should be empty after initialization.");

        // Modify a mapped property
        $entity->name = 'Updated Name'; // Uses __set for 'name' property
        $this->assertTrue($entity->isDirty(), "Entity should be dirty after property modification.");
        $this->assertSame(['name' => 'Updated Name'], $entity->getDirtyProperties(), "Dirty properties should contain the modified property.");

        // Modify another mapped property
        $entity->isActive = true;
        $this->assertTrue($entity->isDirty(), "Entity should still be dirty.");
        $this->assertEquals( // assertEquals for comparing arrays with potential order differences or if values change type
            ['name' => 'Updated Name', 'isActive' => true],
            $entity->getDirtyProperties(),
            "Dirty properties should contain all modified properties."
        );

        // Set property back to original value
        $entity->name = 'Initial Name'; // Name was 'Initial Name' in $this->data after construction + markAsPristine
        $this->assertTrue($entity->isDirty(), "Entity should still be dirty if other props changed (isActive).");
        $this->assertEquals(
            ['isActive' => true], // 'name' is no longer dirty as it's back to original
            $entity->getDirtyProperties(),
            "Dirty properties should not contain 'name' if set back to original."
        );

        // Set a new property (that is mapped)
        $entity->ageValue = 42;
        $this->assertEquals(
            ['isActive' => true, 'ageValue' => 42],
            $entity->getDirtyProperties()
        );
        // Set it to the same value again
        $entity->ageValue = 42;
         $this->assertEquals(
            ['isActive' => true, 'ageValue' => 42],
            $entity->getDirtyProperties(),
            "Setting to same value should not change dirty status for ageValue."
        );
    }

    public function testMarkAsPristine()
    {
        // Constructor expects DB column names
        $entity = new TestAttributeEntity(['entity_id' => 1, 'entity_name' => 'Initial Name']);
        // Properties are set via __set which might make them dirty if they differ from defaults,
        // then constructor calls markAsPristine. So it's clean.
        $this->assertFalse($entity->isDirty());

        $entity->name = 'Updated Name';
        $entity->isActive = true;

        $this->assertTrue($entity->isDirty());
        $this->assertNotEmpty($entity->getDirtyProperties());

        $entity->markAsPristine();

        $this->assertFalse($entity->isDirty(), "Entity should not be dirty after markAsPristine.");
        $this->assertEmpty($entity->getDirtyProperties(), "Dirty properties should be empty after markAsPristine.");

        $this->assertSame('Updated Name', $entity->name, "Data should be updated after markAsPristine.");
        $this->assertTrue($entity->isActive, "New data should be present after markAsPristine.");
    }

    public function testSettingPropertyToSameOriginalValue()
    {
        $entity = new TestAttributeEntity(['entity_id' => 1, 'entity_name' => 'Initial Name']);
        // Constructor calls markAsPristine, so 'Initial Name' is in $this->data

        $this->assertFalse($entity->isDirty(), "Entity should not be dirty initially.");
        $entity->name = 'Initial Name'; // Setting to the same value it already has in $this->data
        $this->assertFalse($entity->isDirty(), "Entity should not be dirty after setting a property to its original value.");
        $this->assertEmpty($entity->getDirtyProperties(), "Dirty properties should be empty.");
    }

    public function testNewPropertyThenSetBackToOriginalLikeValue()
    {
        $entity = new TestAttributeEntity(['entity_id' => 1]); // 'name' is not in initial data

        $entity->name = 'First Set'; // 'name' is now in dirtyData
        $this->assertTrue($entity->isDirty());
        $this->assertSame(['name' => 'First Set'], $entity->getDirtyProperties());

        $entity->markAsPristine(); // 'name' => 'First Set' is now in $this->data
        $this->assertFalse($entity->isDirty());

        $entity->name = 'Second Set'; // 'name' is now 'Second Set' in dirtyData
        $this->assertTrue($entity->isDirty());
        $this->assertSame(['name' => 'Second Set'], $entity->getDirtyProperties());

        $entity->name = 'First Set'; // Set back to the value now in $this->data for 'name'
        $this->assertFalse($entity->isDirty(), "Entity should not be dirty if property is set back to its now pristine value.");
        $this->assertEmpty($entity->getDirtyProperties(), "Dirty properties should be empty.");
    }

    public function testHasManyRelationshipMetadata()
    {
        $metadata = UserWithHasMany::getMetadata();

        $this->assertArrayHasKey('posts', $metadata->relations, "Relations metadata should include 'posts'.");
        $relation = $metadata->relations['posts'];

        $this->assertEquals('HasMany', $relation['type']);
        $this->assertEquals(PostWithBelongsTo::class, $relation['relatedEntity']);
        $this->assertEquals('user_id', $relation['foreignKey']); // Column on PostWithBelongsTo table
        $this->assertEquals('id', $relation['localKey']);       // Column on UserWithHasMany table (its PK)
        $this->assertEquals('posts', $relation['propertyName']);
    }

    public function testHasManyRelationshipMetadataDefaultsLocalKey()
    {
        // UserWithHasManyDefaultLocalKey has #[Column(name: 'custom_id')] for its 'id' property.
        $metadata = UserWithHasManyDefaultLocalKey::getMetadata();

        $this->assertArrayHasKey('postsDefaultKey', $metadata->relations, "Relations metadata should include 'postsDefaultKey'.");
        $relation = $metadata->relations['postsDefaultKey'];

        $this->assertEquals('HasMany', $relation['type']);
        $this->assertEquals(PostWithBelongsTo::class, $relation['relatedEntity']);
        $this->assertEquals('user_id', $relation['foreignKey']); // Column on PostWithBelongsTo table

        // Assert that localKey defaults to the *column name* of the primary key of UserWithHasManyDefaultLocalKey
        $this->assertEquals('custom_id', $relation['localKey'], "LocalKey should default to the PK column name 'custom_id'.");
        $this->assertEquals('postsDefaultKey', $relation['propertyName']);
    }
}

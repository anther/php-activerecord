<?php
class PropertyAmenityCamelKey extends ActiveRecord\Model
{
	static $table_name = 'property_amenities_camel_key';
	static $primary_key = 'id';

	static $belongs_to = array(
		array('amenities', 'foreign_key'=>'AmenityId','class_name'=>'AmenityCamel'),
		array('property', 'foreign_key'=>'PropertyId','class_name'=>'PropertyCamel'),
	);
}
class PropertyCamel extends ActiveRecord\Model
{
	static $table_name = 'property';
	static $primary_key = 'property_id';

	static $has_many = array(
		array('property_amenities','class_name'=>'PropertyAmenityCamelKey','foreign_key'=>'PropertyId'),
		array('amenities', 'through' => 'property_amenities','class_name'=>'AmenityCamel')
	);
};
class AmenityCamel extends ActiveRecord\Model
{
	static $table_name = 'amenities';
	static $primary_key = 'amenity_id';

	static $has_many = array(
		array('property_amenities','class_name'=>'PropertyAmenityCamelKey','foreign_key'=>'AmenityId'),
	);
};
?>
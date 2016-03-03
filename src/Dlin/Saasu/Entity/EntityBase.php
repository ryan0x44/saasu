<?php
namespace Dlin\Saasu\Entity;

use Dlin\Saasu\Task\Task;

class EntityBase

{
    //common entity fields
    public $uid;
    public $lastUpdatedUid;
    public $utcLastModified;

    //name of the entity
    protected  $_entityName;




    /**
     * Extra data holder, if Saasu web service return extra fields.
     *
     * This happens when trying to search for a list of entities (e.g. invoice), Saasu actually return extra key<->value pairs
     * that are not required to make CRUD request.
     *
     * @var array
     */
    private $_extraData;

    public function setExtra($name, $value){
        $this->_extraData[$name] = $value;
    }

    public function getExtra($name){
        return isset($this->_extraData[$name]) ? $this->_extraData[$name] : null;
    }

    /**
     * Constructor
     *
     * @param null $uid
     */
    public function __construct($uid = null)
    {
        $this->uid = $uid;
        $class = explode('\\', get_class($this));
        $this->_entityName = lcfirst(end($class));
        $this->_extraData = array();
        $this->_uidPosition = 'attribute';
        $this->_arrayElementTypes = array();

    }

    /**
     * This is used by the api when saving an entity;
     * By default if the entity being saved dose not have a UID, new entity will be created;
     * If the entity has already got a UID,  an existing entity will be updated;
     * However, the entity could override this behavior by overriding this method. E.g. BuildComboItem operation
     *
     */
    public function getSaveOperationName(){
        return $this->uid ? Task::TASK_TYPE_UPDATE : Task::TASK_TYPE_INSERT;
    }


    /**
     * Return the name of the entity.
     *
     * This is used in the webservice URI for some services e.g. get a invoice
     *
     * @return mixed
     */
    public function getName()
    {
        return $this->_entityName;
    }


    //Sassu is very inconsistent on uid placement, some entities need it
    //be attribute, others need it be child element
    protected  $_uidPosition;

    /**
     *This is used to decide in XML output, the UID is format as an attribute or a child element
     *
     * @return string
     */
    public function getUidPlacement()
    {
        return $this->_uidPosition == 'attribute' ? 'attribute':'element';
    }


    /**
     * @var array
     */
    protected $_arrayElementTypes;

    /**
     * If the entity has a field of array type, this will work out what is the type of the elements in that array
     *
     * @param $name
     * @return mixed
     */
    public function getArrayElementType($name)
    {
        return  array_key_exists($name ,$this->_arrayElementTypes) ? $this->_arrayElementTypes[$name] : null;
    }







    /**
     * Out put xml
     *
     * @param \XMLWriter $oXMLout
     * @param bool $withRoot
     * @return string
     */
    public function toXML(&$oXMLout = null)
    {
        if ($oXMLout === null) {
            $oXMLout = new \XMLWriter();
            $oXMLout->openMemory();
            $oXMLout->setIndent(true);
            $oXMLout->setIndentString('    ');
        }

        $func = function ($obj, $basename = null) use (&$func, &$oXMLout) {
            $className = explode('\\', get_class($obj));

            $className = $basename ? $basename : lcfirst(end($className));

            $oXMLout->startElement($className);

            $vars = get_object_vars($obj);


            //move uid and updateid to the begining
            $vars = array('uid' => $vars['uid'], 'lastUpdatedUid' => $vars['lastUpdatedUid']) + $vars;

            foreach ($vars as $key => $value) {

                if ($value === null || strpos($key, '_') === 0) {
                    continue;
                }

                if (is_array($value)) {
                    $oXMLout->startElement($key);
                    foreach ($value as $subValue) {
                        if ($subValue instanceof EntityBase) {
                            $func($subValue, $subValue->getName());
                        }
                    }
                    $oXMLout->endElement();

                } elseif ($value instanceof EntityBase) {
                     $func($value, $key);

                } elseif (($key == 'uid' || $key == 'lastUpdatedUid') && $obj->getUidPlacement() == 'attribute') {
                    if ($value != '') {
                        $oXMLout->writeAttribute($key, $value);
                    }
                } else {
                    $oXMLout->writeElement($key, (string)$value);
                }
            }
            $oXMLout->endElement();

        };


        $func($this);

        $string = $oXMLout->outputMemory();

        //remove empty tags
        $regexps = array (
            '~<(\w+)\b[^\>]*>\s*</\\1>~',
            '~<\w+\s*/>~'
        );

        do
        {
            $temp = $string;
            $string = preg_replace ($regexps, '', $temp);
            $string = str_replace("    \n", '', $string);
        }
        while ($string != $temp);

        //remove extra spaces


        return $string;

    }

    /**
     *
     * Load data from given xml
     *
     * @param $xml
     */
    public function fromXML($xml)
    {


        $func = function ($xmlElement, &$entity) use (&$func) {


            $vars = array_keys(get_object_vars($entity));

            $usedVars = array();

            //add a dummy field, that is used in XXXXListResponse
            $entityName = $entity->getName();

            foreach($xmlElement->children() as $sub){

                $fieldName = $sub->getName();

                if(!in_array($fieldName, $vars) && substr($fieldName, 0, strlen($entityName)) == $entityName){
                    $fieldName = substr($fieldName, strlen($entityName));
                    $fieldName = lcfirst($fieldName);
                }

                if ( strpos($fieldName, '_') === 0) {
                    continue;
                }


                if(in_array($fieldName, $vars)){
                    $usedVars[] = $fieldName;

                    if (is_array($entity->$fieldName)) {

                        foreach ($sub as $child) {
                            
                                if($entity->getArrayElementType($fieldName) === null){
                                    $entity->{$fieldName}[] = $child;
                                }else{
                                    $subClass = __NAMESPACE__ . '\\' . ucfirst($entity->getArrayElementType($fieldName));
                                    $obj = new $subClass();
                                    $func($child, $obj);
                                    $entity->{$fieldName}[] = $obj;
                                }
                            
                        }


                    } else if (is_object($entity->$fieldName) && $sub) {

                        $func($sub, $entity->$fieldName);

                    } else if (trim((string)$sub) != '') {
                        $entity->$fieldName = (string)$sub;
                    } else {
                        //$entity->$fieldName = null;
                    }

                }else{
                    $entity->setExtra($fieldName, (string)$sub);

                }
            }


            foreach($xmlElement->attributes() as $fieldName => $v){
                if(!in_array($fieldName, $vars) && substr($fieldName, 0, strlen($entityName)) == $entityName){
                    $fieldName = substr($fieldName, strlen($entityName));
                    $fieldName = lcfirst($fieldName);
                }


                if ( strpos($fieldName, '_') === 0) {
                    continue;
                }

                if(in_array($fieldName, $vars)){
                    $usedVars[] = $fieldName;
                    if (trim((string)$v) != '') {
                        $entity->$fieldName = (string)$v;
                    } else {
                        $entity->$fieldName = null;
                    }
                }
            }

            //ok, set null to the rest fields
            $fields = array_diff($vars, $usedVars);


            foreach($fields as $fieldName){
                if ( strpos($fieldName, '_') === 0) {
                    continue;
                }
                $entity->$fieldName =null;
            }


        };

        $xmlElement = simplexml_load_string($xml);
        $func($xmlElement, $this);


    }


}

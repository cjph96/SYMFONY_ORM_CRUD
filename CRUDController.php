<?php

namespace App\Controller;

use Doctrine\DBAL\DriverManager;
use Symfony\Component\Config\Definition\Exception\Exception;

/**
 * CRUD CONTROLER
 * ORM Symfony para hacer consultas a base de datos sin generar varios modelos por tabla a partir de
 * SQL Query Builder de Doctrine: https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/query-builder.html
 * Eñ builder tenia algunas incosistencias entre consultas SELECT, INSERT y UPDATE por lo que en algunos casos he hecho la
 * consulta SQL en lugar de aplicar el builder.
 * @author Cristian J. Pérez Hernández
 */

class CRUDController
{
    protected $connectionParams = array(
        'url' => 'mysql://db_user:db_user_password@127.0.0.1:3306/db_name?serverVersion=10.4.6-MariaDB', //https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html#connecting-using-a-url
    );

    protected $table      = ''; //Tabla sobre la que se va a operar
	protected $primaryKey = ''; //Columna por la que se va a consultar

	protected $useSoftDeletes = true; //Filtro para no mostrar las filas que hayamos "borrado"

	protected $dateFormat = 'datetime'; //Formato de fechas
	protected $useTimestamps = true; //Filtro para saber si se están guardando las fechas de creación, actualización y borrado de una fila
	protected $createdField  = 'created_at'; //Nombre de la columna con la fecha de creación
	protected $updatedField  = 'updated_at'; //Nombre de la columna con la fecha de actualización
	protected $deletedAtField = 'deleted_at'; //Nombre de la columna con la fecha de borrado
    protected $deletedField  = 'deleted'; // Nombre de la columna en el caso de usar sofdelete
    
    protected $db; //Conexión con la base de datos
    protected $builder; //Constructor de consultas

    function __construct($table = '' , $pk = '')
    {
        $this->table = $table;
		$this->primaryKey = $pk;
        
        $this->db = DriverManager::getConnection($this->connectionParams);
        $this->builder = $this->db->createQueryBuilder();
    }

    /**
     * $limit (limite de filas de la consulta)
     * $offset (posicion )
     * Devuelve un array con todos los datos de la tabla
     */
    function read(int $limit = 0, int $offset = 0)
	{
		if ($this->useSoftDeletes === true)
			$this->builder->where($this->deletedField. '='. 0);

        $this->builder
            ->select('*')
            ->from($this->table);
        if($limit >= 1){
            $this->builder
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        }

        $statement = $this->db->executeQuery($this->builder);
        return $statement->fetchAllAssociative();
    }

    /**
     * $order (columna de la tabla por el que se ordena)
     * $type (tipo de orden: 'ASC' o 'DESC')
     * $limit (limite de filas de la consulta)
     * $offset (posicion )
     * Devuelve un array con todos los datos de la tabla ordenados
     */
    function read_ordered(string $order, string $type, int $limit = 0, int $offset = 0)
	{
		if ($this->useSoftDeletes === true)
            $this->builder->where($this->deletedField. '='. 0);
            
        $this->builder
            ->select('*')
            ->from($this->table)
            ->orderBy($order, $type);
        if($limit >= 1){
            $this->builder
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        }

		$statement = $this->db->executeQuery($this->builder);
        return $statement->fetchAllAssociative();
	}
    
    /**
     * $data (datos que se van a introducir)
     * Realiza una insersión en la base de datos
     */
    function add($data)
	{
		if (empty($data))
			throw new Exception('Data empty');

		if (is_object($data))
			$data = (array) $data;

        $date = $this->setDate();

		if ($this->useTimestamps && ! empty($this->createdField) && ! array_key_exists($this->createdField, $data))
			$data[$this->createdField] = $date;

		if ($this->useTimestamps && ! empty($this->updatedField) && ! array_key_exists($this->updatedField, $data))
            $data[$this->updatedField] = $date;
            
        $query_1 = "(";$query_2 = "("; 
        $i = 0;
        foreach ($data as $key => $value) {
            if($i>0){
                $query_1.= ',';$query_2.= ',';
            }
            $query_1.= $key;
            $query_2.= "'". $value .  "'";
            $i++;
        }
        $query_1 .= ")"; $query_2 .= ")";    

        $statement = $this->db->executeQuery( "INSERT INTO " . $this->table . $query_1 . " VALUES " . $query_2);
	    return $statement;
    }

    /**
     * $id (valor de identificacion de la tabla)
     * $data (datos que se van a actualizar)
     * Actualiza una fila en la base de datos
     */
    function update($id = null, $data = null)
	{
		if (empty($data))
            throw new Exception('Data empty');

		if (is_object($data))
			$data = (array) $data;

		if ($this->useTimestamps && ! empty($this->updatedField) && ! array_key_exists($this->updatedField, $data))
            $data[$this->updatedField] = $this->setDate();
            
        $query = "";
        $i = 0;
        foreach ($data as $key => $value) {
            if($i>0){
                $query.= ', ';
            }
            $query.= $key."='".$value."'";
            $i++;
        }

        $statement = $this->db->executeQuery( "UPDATE " . $this->table . " SET " . $query . " WHERE " . $this->primaryKey . "=" . "'". $id ."'");
        return $statement;
    }
    
    /**
     * $id (valor de identificacion de la tabla)
     * $purge (En caso de que se quiera borrar la fila de la base de datos, usar "true")
     * Marca como borrado o borra directamente una fila de la base de datos
     */
    function delete($id = null, bool $purge = false)
	{
		if ($this->useSoftDeletes && ! $purge)
		{
			$set[$this->deletedAtField] = $this->setDate();
			$set[$this->deletedField] = 1;

			if ($this->useTimestamps && ! empty($this->updatedField))
                $set[$this->updatedField] = $this->setDate();
                
            $query = "";
            $i = 0;
            foreach ($set as $key => $value) {
                if($i>0){
                    $query.= ', ';
                }
                $query.= $key."='".$value."'";
                $i++;
            }

            $statement = $this->db->executeQuery( "UPDATE " . $this->table . " SET " . $query . " WHERE " . $this->primaryKey . "=" . "'". $id ."'");
            return $statement;
		}
		else
		{
            $statement = $this->db->executeQuery( "DELETE FROM " . $this->table . " WHERE " . $this->primaryKey . "=" . "'". $id ."'");
            return $statement;
		}
    }
    
    /**
     * $id (valor de identificacion de la tabla)
     * Devuelve una fila de la base de datos
     */
    function one($id = null)
	{
		if ($this->useSoftDeletes === true)
            $this->builder->where($this->deletedField. '='. 0);
        
        $this->builder->select('*')->from($this->table)->andWhere($this->primaryKey. "='".  $id ."'");
        $row =  $this->db->fetchAssociative($this->builder);
        return $row;
    }
    
    /**
     * $array (Parametros que se buscan)
     * $limit (limite de filas de la consulta)
     * $offset (posicion )
     * $order (columna de la tabla por el que se ordena)
     * $type (tipo de orden: 'ASC' o 'DESC')
     * Devuelve un array con todas las filas coincidentes
     */
    function plenty($array, int $limit = 0, $offset =0, $order = null, $type = "DESC")
	{
        if ($this->useSoftDeletes === true)
            $this->builder->where($this->deletedField. '='. 0);

        foreach ($array as $key => $value) {
            $this->builder->andwhere($key. '="'. $value . '"');
        }

        $this->builder->select('*')->from($this->table);
        if($order !== null){
            $this->builder
                ->orderBy($order, $type);
        }
        if($limit >= 1){
            $this->builder
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        }
        $statement = $this->db->executeQuery($this->builder);
        return $statement->fetchAllAssociative();
    }

    /**
     * $id (valor de identificacion de la tabla)
     * Devuelve un boolean que comprueba la existencia de los datos
     */
    function exist($id)
	{
		if ($this->useSoftDeletes === true)
            $this->builder->where($this->deletedField. '='. 0);

        $this->builder
            ->select('count(id) as total')
            ->from($this->table)
            ->andWhere($this->primaryKey. "='".  $id ."'");

        $statement = $this->db->fetchAllAssociative($this->builder);

		$row = $statement[0];

		return (($row["total"] > 0)) ? true : false;
	}
    
    /**
     * Establece una fecha
    */
    protected function setDate(int $userData = null)
	{
		$currentDate = is_numeric($userData) ? (int) $userData : time();

		switch ($this->dateFormat)
		{
			case 'int':
				return $currentDate;
				break;
			case 'datetime':
				return date('Y-m-d H:i:s', $currentDate);
				break;
			case 'date':
				return date('Y-m-d', $currentDate);
				break;
			default:
                throw new Exception('Error');
		}
	}

}

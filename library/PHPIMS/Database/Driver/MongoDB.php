<?php
/**
 * PHPIMS
 *
 * Copyright (c) 2011 Christer Edvartsen <cogo@starzinger.net>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * * The above copyright notice and this permission notice shall be included in
 *   all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @package PHPIMS
 * @subpackage DatabaseDriver
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @copyright Copyright (c) 2011, Christer Edvartsen
 * @license http://www.opensource.org/licenses/mit-license MIT License
 * @link https://github.com/christeredvartsen/phpims
 */

namespace PHPIMS\Database\Driver;

use PHPIMS\Database\Exception as DatabaseException;
use PHPIMS\Database\DriverInterface;
use PHPIMS\Image;
use PHPIMS\Operation\GetImages\Query;

/**
 * MongoDB database driver
 *
 * A MongoDB database driver for PHPIMS
 *
 * Valid parameters for this driver:
 *
 * - <pre>(string) database</pre> Name of the database. Defaults to 'phpims'
 * - <pre>(string) collection</pre> Name of the collection to store data in. Defaults to 'images'
 *
 * @package PHPIMS
 * @subpackage DatabaseDriver
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @copyright Copyright (c) 2011, Christer Edvartsen
 * @license http://www.opensource.org/licenses/mit-license MIT License
 * @link https://github.com/christeredvartsen/phpims
 */
class MongoDB implements DriverInterface {
    /**
     * The collection instance used by the driver
     *
     * @var MongoCollection
     */
    private $collection = null;

    /**
     * Parameters for the driver
     *
     * @var array
     */
    private $params = array(
        'databaseName'   => 'phpims',
        'collectionName' => 'images',
    );

    /**
     * Class constructor
     *
     * @param array $params Parameters for the driver
     * @param MongoCollection $collection MongoDB collection instance
     */
    public function __construct(array $params = null, \MongoCollection $collection = null) {
        if ($params !== null) {
            $this->params = array_merge($this->params, $params);
        }

        if ($collection === null) {
            // @codeCoverageIgnoreStart
            $mongo      = new \Mongo();
            $database   = $mongo->{$this->params['databaseName']};
            $collection = $database->{$this->params['collectionName']};
        }
        // @codeCoverageIgnoreEnd

        $this->collection = $collection;
    }

    /**
     * @see PHPIMS\Database\DriverInterface::insertImage()
     */
    public function insertImage($imageIdentifier, Image $image) {
        $data = array(
            'name'            => $image->getFilename(),
            'size'            => $image->getFilesize(),
            'imageIdentifier' => $imageIdentifier,
            'mime'            => $image->getMimeType(),
            'metadata'        => array(),
            'added'           => time(),
            'width'           => $image->getWidth(),
            'height'          => $image->getHeight(),
        );

        try {
            // See if the image already exists
            $row = $this->collection->findOne(array('imageIdentifier' => $data['imageIdentifier']));

            if ($row) {
                throw new DatabaseException('Image already exists', 400);
            }

            $this->collection->insert($data, array('safe' => true));
        } catch (\MongoException $e) {
            throw new DatabaseException('Unable to save image data', 500, $e);
        }

        return true;
    }

    /**
     * @see PHPIMS\Database\DriverInterface::deleteImage()
     */
    public function deleteImage($imageIdentifier) {
        try {
            $this->collection->remove(array('imageIdentifier' => $imageIdentifier), array('justOne' => true, 'safe' => true));
        } catch (\MongoException $e) {
            throw new DatabaseException('Unable to delete image data', 500, $e);
        }

        return true;
    }

    /**
     * @see PHPIMS\Database\DriverInterface::editMetadata()
     */
    public function updateMetadata($imageIdentifier, array $metadata) {
        try {
            $this->collection->update(
                array('imageIdentifier' => $imageIdentifier),
                array('$set' => array(
                    'metadata' => $metadata,
                )),
                array(
                    'safe' => true,
                    'multiple' => false,
                )
            );
        } catch (\MongoException $e) {
            throw new DatabaseException('Unable to edit image data', 500, $e);
        }

        return true;
    }

    /**
     * @see PHPIMS\Database\DriverInterface::getMetadata()
     */
    public function getMetadata($imageIdentifier) {
        try {
            $data = $this->collection->findOne(array('imageIdentifier' => $imageIdentifier));
        } catch (\MongoException $e) {
            throw new DatabaseException('Unable to fetch image metadata', 500, $e);
        }

        return isset($data['metadata']) ? $data['metadata'] : array();
    }

    /**
     * @see PHPIMS\Database\DriverInterface::deleteMetadata()
     */
    public function deleteMetadata($imageIdentifier) {
        try {
            $this->updateMetadata($imageIdentifier, array());
        } catch (DatabaseException $e) {
            throw new DatabaseException('Unable to remove metadata', 500, $e);
        }

        return true;
    }

    /**
     * @see PHPIMS\Database\DriverInterface::getImages()
     */
    public function getImages(Query $query) {
        // Initialize return value
        $images = array();

        // Query data
        $queryData = array();

        $from = $query->from();
        $to = $query->to();

        if ($from || $to) {
            $tmp = array();

            if ($from !== null) {
                $tmp['$gt'] = $from;
            }

            if ($to !== null) {
                $tmp['$lt'] = $to;
            }

            $queryData['added'] = $tmp;
        }

        $metadataQuery = $query->query();

        if (!empty($metadataQuery)) {
            $queryData['metadata'] = $metadataQuery;
        }

        // Fields to fetch
        $fields = array('added', 'imageIdentifier', 'mime', 'name', 'size', 'width', 'height');

        if ($query->returnMetadata()) {
            $fields[] = 'metadata';
        }

        try {
            $cursor = $this->collection->find($queryData, $fields)
                                       ->limit($query->num())
                                       ->sort(array('added' => -1));

            // Skip some images if a page has been set
            if (($page = $query->page()) > 1) {
                $skip = $query->num() * ($page - 1);
                $cursor->skip($skip);
            }

            foreach ($cursor as $image) {
                unset($image['_id']);
                $images[] = $image;
            }
        } catch (\MongoException $e) {
            throw new DatabaseException('Unable to search for images', 500, $e);
        }

        return $images;
    }

    /**
     * @see PHPIMS\Database\DriverInterface::load()
     */
    public function load($imageIdentifier, Image $image) {
        try {
            $fields = array('name', 'size', 'width', 'height', 'mime');
            $data = $this->collection->findOne(array('imageIdentifier' => $imageIdentifier), $fields);
        } catch (\MongoException $e) {
            throw new DatabaseException('Unable to fetch image data', 500, $e);
        }

        $image->setFilename($data['name'])
              ->setFilesize($data['size'])
              ->setWidth($data['width'])
              ->setHeight($data['height'])
              ->setMimeType($data['mime']);

        return true;
    }
}
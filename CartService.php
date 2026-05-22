<?php

namespace App\Services;

use App\Contracts\DiscountRepositoryInterface;
use App\Contracts\ProductRepositoryInterface;
use App\Rules\ProductExists;
use App\Rules\ProductInStock;
use App\Rules\SufficientStock;
use App\Rules\ValidDiscountCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class CartService
{
    private Collection $items;

    private ?array $appliedDiscount = null;

    public function __construct(
        private ProductRepositoryInterface $products,
        private DiscountRepositoryInterface $discounts
    ) {
        $this->items = collect();
    }

    /**
     * Add item to cart
     */
    public function addItem(int $productId, int $quantity): array
    {
        $currentCartQuantity = $this->items->has($productId)
            ? $this->items->get($productId)['quantity']
            : 0;

        /**
         * From inspection, the order of validation is important,  below is the sequence
         * 1. product_id validations 
         * 2. quantity validations 
         * trying to match validation order for tests
         */
        $validator = Validator::make(
            [
                'product_id' => $productId,
                'quantity'   => $quantity,
            ],
            [
                'product_id' => [
                    new ProductExists($this->products),
                    new ProductInStock($this->products),
                ],

                'quantity' => [
                    'required',
                    'integer',
                    'min:1',
                    new SufficientStock(
                        $this->products,
                        $currentCartQuantity
                    ),
                ],
            ],
            [
                'quantity.min' => 'Invalid quantity',
            ]
        );

        if ($validator->fails()) {
            return [
                'success' => false,
                'message' => $validator->errors()->first(),
                'cart'    => $this->getCart(),
            ];
        }

        $product = $this->products->find($productId);

        if ($this->items->has($productId)) {

            $item = $this->items->get($productId);

            $item['quantity'] += $quantity;
            $item['subtotal'] = $item['price'] * $item['quantity'];

            $this->items->put($productId, $item);

        } else {

            $this->items->put($productId, [
                'product_id' => $product['id'],
                'name'       => $product['name'],
                'price'      => $product['price'],
                'quantity'   => $quantity,
                'subtotal'   => $product['price'] * $quantity,
            ]);
        }

        return [
            'success' => true,
            'message' => 'Item added',
            'cart'    => $this->getCart(),
        ];
    }

    /**
     * Update item quantity
     */
    public function updateQuantity(int $productId, int $quantity): array
    {
        /**
         * Item must exist in cart
         */
        if (!$this->items->has($productId)) {
            return [
                'success' => false,
                'message' => 'Item not in cart',
                'cart'    => $this->getCart(),
            ];
        }

        /**
         * Quantity cannot be negative
         */
        if ($quantity < 0) {
            return [
                'success' => false,
                'message' => 'Invalid quantity',
                'cart'    => $this->getCart(),
            ];
        }

        /**
         * Remove item if quantity = 0
         */
        if ($quantity === 0) {

            $this->items->forget($productId);

            return [
                'success' => true,
                'message' => 'Item removed from cart',
                'cart'    => $this->getCart(),
            ];
        }

        /**
         * Validate stock
         */
        $availableStock = $this->products->getStock($productId);

        if ($quantity > $availableStock) {
            return [
                'success' => false,
                'message' => 'Insufficient stock',
                'cart'    => $this->getCart(),
            ];
        }

        $item = $this->items->get($productId);

        $item['quantity'] = $quantity;
        $item['subtotal'] = $item['price'] * $quantity;

        $this->items->put($productId, $item);

        return [
            'success' => true,
            'message' => 'Quantity updated',
            'cart'    => $this->getCart(),
        ];
    }

    /**
     * Remove item from cart
     */
    public function removeItem(int $productId): array
    {
        if (!$this->items->has($productId)) {
            return [
                'success' => false,
                'message' => 'Item not in cart',
                'cart'    => $this->getCart(),
            ];
        }

        $this->items->forget($productId);

        return [
            'success' => true,
            'message' => 'Item removed from cart',
            'cart'    => $this->getCart(),
        ];
    }

    /**
     * Apply discount code
     */
    public function applyDiscount(string $code): array
    {
        $validator = Validator::make(
            [
                'code' => $code,
            ],
            [
                'code' => [
                    new ValidDiscountCode(
                        $this->discounts,
                        $this->getSubtotal(),
                        $this->appliedDiscount !== null
                    ),
                ],
            ]
        );

        if ($validator->fails()) {
            return [
                'success' => false,
                'message' => $validator->errors()->first(),
                'cart'    => $this->getCart(),
            ];
        }

        $this->appliedDiscount = $this->discounts->findByCode($code);

        return [
            'success' => true,
            'message' => 'Discount applied',
            'cart'    => $this->getCart(),
        ];
    }

    /**
     * Get cart items
     */
    public function getItems(): array
    {
        return $this->items->values()->toArray();
    }

    /**
     * Get subtotal
     */
    public function getSubtotal(): float
    {
        return (float) $this->items->sum('subtotal');
    }

    /**
     * Get discount amount
     */
    public function getDiscountAmount(): float
    {
        if (!$this->appliedDiscount) {
            return 0;
        }

        if ($this->appliedDiscount['type'] === 'percentage') {

            return round(
                ($this->getSubtotal() * $this->appliedDiscount['value']) / 100,
                2
            );
        }

        return min(
            $this->appliedDiscount['value'],
            $this->getSubtotal()
        );
    }

    /**
     * Get total
     */
    public function getTotal(): float
    {
        return round(
            $this->getSubtotal() - $this->getDiscountAmount(),
            2
        );
    }

    /** Get full cart */
    public function getCart(): array
    {
        return [
            'items'    => $this->getItems(),
            'subtotal' => round($this->getSubtotal(), 2),
            'discount' => round($this->getDiscountAmount(), 2),
            'total'    => round($this->getTotal(), 2),
        ];
    }

    /**
     * Clear cart
     */
    public function clearCart(): array
    {
        $this->items = collect();
        $this->appliedDiscount = null;

        return [
            'success' => true,
            'message' => 'Cart cleared',
            'cart'    => $this->getCart(),
        ];
    }
}

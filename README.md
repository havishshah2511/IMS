# Inventory Management System

A small PHP and SQLite web application for managing product stock.

## Features

- Add products with product attribute, size, unit, initial quantity, reorder quantity, and supplier name.
- Add inward stock with supplier details.
- Subtract stock against an order reference.
- View order-wise stock deduction history.
- View complete stock movement history.
- Automatic low-stock reorder status.

## Run

For the plain HTML version, open `inventory.html` in your browser.

For the PHP version, install PHP with the SQLite extension enabled, then run:

```powershell
php -S localhost:8000
```

Open:

```text
http://localhost:8000
```

The database is created automatically at `data/inventory.sqlite`.

## Example Product

- Product Name: `Compostable Carry bag`
- Product Attribute: `Carry bag`
- Size: `13 X 16`
- Unit: `KG`

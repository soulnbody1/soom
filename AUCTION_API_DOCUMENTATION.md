# Auction API Documentation

## Public

- `GET /api/auctions`
- `GET /api/auctions/{auction_public_id}`
- `GET /api/auctions/{auction_public_id}/bids`
- `GET /api/soom/payment-methods`
- `GET /api/soom/auction-terms`

## Authenticated

- `POST /api/soom/auctions`
- `POST /api/soom/auctions/{auction}/submit-review`
- `POST /api/soom/auctions/{auction}/seller-deposit`
- `POST /api/soom/auctions/{auction}/register`
- `POST /api/soom/auctions/{auction}/accept-terms`
- `POST /api/soom/auctions/{auction}/bidder-deposit`
- `POST /api/soom/auctions/{auction}/bids`
- `POST /api/soom/auctions/{auction}/winner-payment`
- `POST /api/soom/auctions/{auction}/complete-handover`
- `DELETE /api/soom/auctions/{auction}`
- `GET /api/soom/my/auctions`
- `GET /api/soom/my/bids`

## Admin

- `GET /api/admin/auctions`
- `POST /api/admin/auctions/{auction}/review`
- `POST /api/admin/auctions/terms`
- `POST /api/admin/auctions/payment-methods`
- `PUT /api/admin/auctions/payment-methods/{paymentMethod}`
- `GET /api/admin/auctions/payment-submissions`
- `POST /api/admin/auctions/payment-submissions/{paymentSubmission}/review`

All auction resources expose public ULIDs as `id`. Money is returned as `{amount, minor, currency}`.

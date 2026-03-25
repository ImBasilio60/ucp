# UCP - Référence des Endpoints API

## Table des matières
1. [Sessions de Paiement](#sessions-de-paiement-checkout_sessions)
2. [Gestion des Clients](#gestion-des-clients-buyers)
3. [API Générale](#api-générale-api)
4. [Well-Known Endpoint](#well-known-endpoint-well-knownucp)

---

## Sessions de Paiement (/checkout_sessions)

### POST /checkout_sessions
**Description**: Créer une nouvelle session de paiement temporaire

**En-têtes requis**:
```
UCP-Agent: {agent_id}
request-id: {uuid}
idempotency-key: {key}
request-signature: {signature}
```

**Payload**:
```json
{
  "line_items": [
    {
      "product_id": 1,
      "quantity": 2,
      "customization_data": {
        "fields": [
          {
            "type": 0,
            "value": "Texte personnalisé",
            "required": 0
          }
        ]
      }
    }
  ],
  "buyer": {
    "email": "client@example.com",
    "first_name": "Jean",
    "last_name": "Dupont",
    "address": "123 rue de la Paix",
    "city": "Paris",
    "postal_code": "75001",
    "country": "France",
    "phone": "+33612345678",
    "company": "Société ABC"
  }
}
```

**Réponses**:
- `201 Created`: Session créée avec succès
- `400 Bad Request`: Données invalides
- `401 Unauthorized`: En-têtes manquants
- `409 Conflict`: Conflit d'idempotence

---

### GET /checkout_sessions
**Description**: Obtenir les informations sur l'endpoint

**Réponse**: Liste des endpoints disponibles

---

### GET /checkout_sessions?sid={id}
**Description**: Récupérer les détails d'une session spécifique

**Réponses**:
- `200 OK`: Détails de la session
- `404 Not Found`: Session introuvable

---

### PUT /checkout_sessions?sid={id}
**Description**: Mettre à jour une session (produits, codes promo)

**Payload**:
```json
{
  "line_items": [
    {
      "product_id": 2,
      "quantity": 1
    }
  ],
  "promo_code": "PROMO20"
}
```

**Réponses**:
- `200 OK`: Session mise à jour
- `400 Bad Request**: Validation échouée
- `404 Not Found`: Session introuvable

---

### POST /checkout_sessions?sid={id}&action=finalize
**Description**: Finaliser une session et créer panier/commande PrestaShop

**Réponses**:
- `200 OK`: Session finalisée
- `400 Bad Request`: Session invalide
- `404 Not Found`: Session introuvable
- `500 Internal Server Error`: Erreur PrestaShop

---

## Gestion des Clients (/buyers)

### GET /buyers
**Description**: Lister tous les clients actifs

**Paramètres optionnels**:
- `limit`: Nombre de résultats (défaut: 10)
- `offset`: Décalage pagination (défaut: 0)
- `anonymize`: Anonymiser données (true/false)
- `include_billing`: Inclure adresse facturation (défaut: true)
- `include_shipping`: Inclure adresse livraison (défaut: true)

**Réponse**:
```json
{
  "status": "success",
  "data": [...],
  "pagination": {
    "total": 150,
    "limit": 10,
    "offset": 0
  }
}
```

---

### GET /buyers?customer_id={id}
**Description**: Récupérer un client spécifique

**Paramètres optionnels**: mêmes que listing

**Réponses**:
- `200 OK`: Détails du client
- `404 Not Found`: Client introuvable

---

### GET /buyers?search={query}
**Description**: Rechercher des clients

**Champs de recherche**: email, nom, prénom, entreprise

**Réponse**:
```json
{
  "status": "success",
  "data": [...],
  "search_info": {
    "query": "jean",
    "total_results": 5
  }
}
```

---

### POST /buyers
**Description**: Conversion en lot de clients

**Payload**:
```json
{
  "customer_ids": [1, 2, 3, 45, 67]
}
```

**Réponses**:
- `200 OK`: Clients convertis
- `400 Bad Request`: IDs invalides

---

## API Générale (/api)

### GET /api
**Description**: Informations système et santé de l'API

**Réponse**:
```json
{
  "status": "success",
  "message": "UCP API endpoint",
  "server_info": {
    "ucp_version": "2026-03-13",
    "prestashop_version": "1.7.8.0",
    "module_version": "1.0.0"
  }
}
```

---

### POST /api
**Description**: Endpoint de test POST

**Réponse**: Echo des données reçues pour validation

---

### PUT /api
**Description**: Endpoint de test PUT

**Réponse**: Echo des données reçues pour validation

---

### DELETE /api
**Description**: Endpoint de test DELETE

**Réponse**: Confirmation de réception

---

## Well-Known Endpoint (/.well-known/ucp)

### GET /.well-known/ucp
**Description**: Endpoint standard UCP (well-known)

**Redirection**: Vers le contrôleur principal du module

**Configuration requise**:
- Apache: RewriteRule dans .htaccess
- Nginx: Location block dans config serveur

---

## En-têtes UCP Obligatoires

Toutes les requêtes doivent inclure:

| En-tête | Description | Format |
|---------|-------------|--------|
| `UCP-Agent` | Identifiant du client | String |
| `request-id` | UUID unique pour traçabilité | UUID v4 |
| `idempotency-key` | Clé d'idempotence | String |
| `request-signature` | Signature HMAC-SHA256 | Hex string |

---

## Codes d'erreur HTTP

| Code | Signification | Causes communes |
|------|---------------|------------------|
| 200 | OK | Requête réussie |
| 201 | Created | Ressource créée |
| 400 | Bad Request | Données invalides, JSON incorrect |
| 401 | Unauthorized | En-têtes manquants/invalides |
| 404 | Not Found | Ressource introuvable |
| 405 | Method Not Allowed | Méthode non supportée |
| 409 | Conflict | Conflit d'idempotence |
| 500 | Internal Server Error | Erreur serveur/DB |

---

## Format des réponses

### Succès
```json
{
  "status": "success",
  "data": {...},
  "request_info": {
    "request_id": "{uuid}",
    "timestamp": "2026-03-24T15:30:00+00:00"
  }
}
```

### Erreur
```json
{
  "error": "Error description",
  "code": 400,
  "details": {...},
  "timestamp": "2026-03-24T15:30:00+00:00"
}
```

---

## Exemples d'utilisation

### Créer et finaliser une session
```bash
# 1. Créer session
curl -X POST https://shop.com/prestashop/module/ucpwellknown/checkout_sessions \
  -H "UCP-Agent: test-client" \
  -H "request-id: $(uuidgen)" \
  -H "idempotency-key: session-001" \
  -H "request-signature: abc123" \
  -d '{"line_items":[...],"buyer":{...}}'

# 2. Finaliser session
curl -X POST "https://shop.com/prestashop/module/ucpwellknown/checkout_sessions?sid=ucs_abc123&action=finalize" \
  -H "UCP-Agent: test-client" \
  -H "request-id: $(uuidgen)" \
  -H "idempotency-key: finalize-001" \
  -H "request-signature: def456"
```

### Rechercher un client
```bash
curl -X GET "https://shop.com/prestashop/module/ucpwellknown/buyers?search=jean%20dupont" \
  -H "UCP-Agent: test-client" \
  -H "request-id: $(uuidgen)" \
  -H "idempotency-key: search-001" \
  -H "request-signature: ghi789"
```

---

*Document généré automatiquement - Version 1.0.0*

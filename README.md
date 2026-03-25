# UCP Module

Module PrestaShop pour l'intégration du protocole UCP (Universal Commerce Protocol) avec gestion complète des sessions de paiement.

## Vue d'ensemble

Le module UCP permet de créer et gérer des sessions de checkout sécurisées avec validation des produits, gestion des clients, application de codes promo et respect complet du protocole UCP.

## Fonctionnalités principales

### 🛒 Gestion des sessions de paiement
- Création de sessions avec identifiant unique
- Mise à jour atomique des produits
- Calcul automatique des totaux
- Gestion des codes promotionnels
- Finalisation et création de commandes PrestaShop

### 👥 Gestion des clients
- Création automatique de comptes clients
- Réutilisation des clients existants
- Validation des informations obligatoires
- Gestion des adresses complètes
- Conversion et recherche de clients

### 🎦 Gestion des produits
- Validation en temps réel du stock
- Support des produits personnalisables
- Ajout, modification et suppression
- Calcul des prix TTC et HT
- Conversion des données produits

### 🎫 Codes promotionnels
- Application et suppression de codes promo
- Validation des limites d'utilisation
- Calcul automatique des réductions
- Support des réductions en pourcentage et montant

### 🔄 Gestion des commandes
- Conversion des paniers en commandes
- Synchronisation des données clients
- Gestion des adresses de livraison
- Suivi des statuts de commande

## Architecture

### Classes principales
- `UcpHeaderValidator` - Validation des en-têtes UCP et authentification
- `UcpCheckoutSessionValidator` - Validation des payloads de sessions
- `UcpSessionManager` - Gestion des sessions temporaires et finalisation
- `UcpCartManager` - Gestion des paniers PrestaShop
- `UcpBuyerManager` - Gestion des clients UCP/PrestaShop
- `UcpCheckoutSessionUpdater` - Mise à jour des sessions
- `UcpBuyerConverter` - Conversion des données clients
- `UcpItemConverter` - Conversion des données produits
- `UcpOrderConverter` - Conversion des commandes

### Controllers
- `checkout_sessions` - Endpoint principal pour les sessions de paiement
- `buyers` - Endpoint pour la gestion des clients
- `api` - Endpoint API général et informations système
- Support des méthodes GET, POST, PUT, DELETE
- Gestion des erreurs structurée
- Logs de sécurité intégrés

## Endpoints API

### 1. Sessions de Paiement (/checkout_sessions)

#### Créer une session de paiement
**Méthode**: POST  
**URL**: `/prestashop/module/ucp/checkout_sessions`

**En-têtes requis**:
- `UCP-Agent`: Identifiant du client
- `request-id`: UUID unique
- `idempotency-key`: Clé d'idempotence
- `request-signature`: Signature de la requête

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

**Réponse**:
```json
{
  "status": "success",
  "checkout_id": "ucs_abc123_1648123456",
  "session_type": "temporary",
  "line_items": [...],
  "buyer": {...},
  "totals": {...},
  "created_at": "2026-03-24T15:30:00+00:00",
  "expires_at": "2026-03-24T16:30:00+00:00",
  "next_steps": {
    "modify_session": "PUT /checkout_sessions?sid=ucs_abc123_1648123456",
    "finalize_session": "POST /checkout_sessions?sid=ucs_abc123_1648123456&action=finalize"
  }
}
```

#### Récupérer une session
**Méthode**: GET  
**URL**: `/prestashop/module/ucp/checkout_sessions?sid={id}`

**Réponse**: Détails complets de la session avec produits, totaux et codes promo appliqués.

#### Mettre à jour une session
**Méthode**: PUT ou POST (avec sid)  
**URL**: `/prestashop/module/ucp/checkout_sessions?sid={id}`

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

#### Finaliser une session
**Méthode**: POST  
**URL**: `/prestashop/module/ucpwellknown/checkout_sessions?sid={id}&action=finalize`

**Payload requis**:
```json
{
  "payment": {
    "method": "card",
    "provider": "stripe",
    "transaction_id": "txn_123456",
    "status": "paid"
  },
  "confirmation": {
    "accepted_terms": true
  }
}
```

**Champs payment obligatoires**:
- `method` - Méthode de paiement (card, paypal, etc.)
- `provider` - Fournisseur de paiement (stripe, adyen, etc.)
- `transaction_id` - ID unique de la transaction
- `status` - Statut du paiement (paid, pending, failed)

**Champs confirmation obligatoires**:
- `accepted_terms` - Doit être `true` pour confirmer l'acceptation

**Réponse**:
```json
{
  "status": "success",
  "checkout_id": "ucs_abc123_1648123456",
  "session_type": "finalized",
  "prestashop_cart_id": 123,
  "prestashop_customer_id": 456,
  "prestashop_order_id": 789,
  "prestashop_order_reference": "ORD-20260324-001",
  "order_created": true,
  "finalized_at": "2026-03-24T15:35:00+00:00"
}
```

#### Informations sur l'endpoint
**Méthode**: GET  
**URL**: `/prestashop/module/ucp/checkout_sessions`

Retourne la liste des endpoints disponibles et leurs descriptions.

### 2. Gestion des Clients (/buyers)

#### Lister tous les clients
**Méthode**: GET  
**URL**: `/prestashop/module/ucp/buyers`

**Paramètres optionnels**:
- `limit`: Nombre de résultats (défaut: 10)
- `offset`: Décalage pour pagination (défaut: 0)
- `anonymize`: Anonymiser les données (true/false)
- `include_billing`: Inclure adresse de facturation (défaut: true)
- `include_shipping`: Inclure adresse de livraison (défaut: true)

#### Récupérer un client spécifique
**Méthode**: GET  
**URL**: `/prestashop/module/ucp/buyers?customer_id={id}`

**Paramètres optionnels**: mêmes que le listing

#### Rechercher des clients
**Méthode**: GET  
**URL**: `/prestashop/module/ucp/buyers?search={query}`

**Recherche par**: email, nom, prénom, entreprise

#### Conversion en lot
**Méthode**: POST  
**URL**: `/prestashop/module/ucp/buyers`

**Payload**:
```json
{
  "customer_ids": [1, 2, 3, 45, 67]
}
```

### 3. API Générale (/api)

#### Informations système
**Méthode**: GET  
**URL**: `/prestashop/module/ucp/api`

**Réponse**:
```json
{
  "status": "success",
  "message": "UCP API endpoint",
  "request_info": {...},
  "server_info": {
    "ucp_version": "2026-03-13",
    "prestashop_version": "1.7.8.0",
    "module_version": "1.0.0"
  }
}
```

#### Test POST/PUT/DELETE
**Méthodes**: POST, PUT, DELETE  
**URL**: `/prestashop/module/ucp/api`

Endpoints de test pour vérifier la connectivité et la validation des en-têtes.

### 4. Well-Known Endpoint (/.well-known/ucp)

#### Endpoint standard UCP
**URL**: `/.well-known/ucp`

Redirigé vers le contrôleur principal du module selon la configuration Apache/Nginx.

## Champs obligatoires du buyer

Les informations suivantes sont requises pour la création d'un client:
- `email` - Adresse email valide
- `first_name` - Prénom
- `last_name` - Nom de famille
- `address` - Adresse de livraison
- `city` - Ville
- `postal_code` - Code postal (4-10 chiffres)
- `country` - Pays (format ISO)
- `phone` - Numéro de téléphone

## Gestion des codes promo

### Application
Envoyez un code promo dans le payload de mise à jour pour l'appliquer automatiquement.

### Suppression
Envoyez une chaîne vide (`"promo_code": ""`) pour supprimer tous les codes promo.

### Validation
Le module vérifie automatiquement:
- L'existence du code promo
- Les limites d'utilisation par client
- La compatibilité avec les produits du panier

## Cycle de vie des sessions

### 1. Session Temporaire
- Créée via POST /checkout_sessions
- Stockée dans le système de fichiers temporaire
- Durée de vie: 1 heure par défaut
- Aucune interaction avec PrestaShop

### 2. Session Finalisée
- Convertie via POST /checkout_sessions?action=finalize
- Création du panier et client PrestaShop
- Optionnellement création de commande
- Les données deviennent persistantes
- **Suppression automatique du fichier JSON après finalisation**

### 3. Nettoyage automatique
- Les fichiers JSON sont automatiquement supprimés après finalisation
- Les sessions expirées sont nettoyées lors de l'accès
- Méthode `cleanupExpiredSessions()` disponible pour maintenance

## Sécurité

### En-têtes UCP obligatoires
Toutes les requêtes doivent inclure:
- `UCP-Agent`: Identification du client
- `request-id`: UUID unique pour traçabilité
- `idempotency-key`: Clé d'idempotence
- `request-signature`: Signature HMAC-SHA256

**Important**: Dans la spécification UCP, ce n'est pas le serveur qui remplit ces en-têtes, mais bien l'agent ou le client qui initie la requête. Chaque en-tête a un rôle précis :

| En-tête | Rôle de l'agent |
|---------|-----------------|
| **UCP-Agent** | L'agent injecte son identifiant pour que le serveur sache quel client envoie la requête |
| **request-id** | L'agent génère un UUID v4 unique pour chaque requête, utilisé pour la traçabilité et le logging côté serveur |
| **idempotency-key** | L'agent fournit cette clé pour garantir que les appels répétés n'entraînent pas d'effets secondaires multiples |
| **request-signature** | L'agent calcule une signature HMAC-SHA256 sur le corps de la requête (souvent avec request-id et clé secrète) et l'inclut pour authentification et intégrité |

En résumé : tous ces en-têtes sont produits côté client/agent, jamais côté serveur. Le serveur les lit pour vérifier la validité et la sécurité de la requête.

### Idempotence
Les clés d'idempotence empêchent les doublons et garantissent la cohérence des opérations.

### Validation
Tous les payloads sont validés avant traitement pour prévenir les injections et les erreurs.

### Logs de sécurité
Toutes les requêtes sont loggées avec:
- Timestamp et request-id
- Endpoint et méthode HTTP
- IP client et user-agent
- Statut de la réponse

## Stockage des sessions

### Emplacement
- Principal: `/modules/ucp/temp/sessions/`
- Fallback: `/tmp/ucp_sessions/`

### Format des fichiers
- Nom: `ucs_{checkout_id}.json`
- Contenu: Session JSON complète
- **Suppression automatique après finalisation**
- Nettoyage automatique après expiration

### Cycle de vie
1. **Création**: Fichier JSON créé dans `temp/sessions/`
2. **Mises à jour**: Fichier modifié lors des updates
3. **Finalisation**: Données sauvegardées, puis fichier supprimé
4. **Expiration**: Nettoyage automatique des fichiers expirés

## Codes d'erreur

### HTTP 400 - Bad Request
- Données invalides ou champs manquants
- Code promo invalide
- Format JSON incorrect
- **Termes et conditions non acceptés** (`TERMS_NOT_ACCEPTED`)
- **Paiement non confirmé** (`PAYMENT_NOT_CONFIRMED`)

### HTTP 401 - Unauthorized
- En-têtes UCP manquants
- Signature invalide

### HTTP 404 - Not Found
- Session ou produit introuvable
- Client inexistant

### HTTP 405 - Method Not Allowed
- Méthode HTTP non supportée

### HTTP 409 - Conflict
- Conflit de clé d'idempotence

### HTTP 500 - Internal Server Error
- Erreur serveur interne
- Base de données inaccessible

## Configuration serveur

### Apache
Ajouter dans `.htaccess`:
```apache
RewriteEngine on
# Well-known
RewriteRule ^\.well-known/ucp$ index.php?fc=module&module=ucp&controller=ucp [QSA,L]
```

### Nginx
Ajouter dans la configuration du site:
```nginx
location = /.well-known/ucp {
    rewrite ^ /index.php?fc=module&module=ucp&controller=ucp last;
}
```

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

### Erreur spécifique - Termes non acceptés
```json
{
  "error": {
    "code": "TERMS_NOT_ACCEPTED",
    "message": "User must accept terms and conditions before finalizing checkout"
  },
  "timestamp": "2026-03-24T15:30:00+00:00"
}
```

### Erreur spécifique - Paiement non confirmé
```json
{
  "error": {
    "code": "PAYMENT_NOT_CONFIRMED",
    "message": "Payment must be confirmed before finalizing checkout",
    "details": {
      "current_status": "pending",
      "expected_status": "paid"
    }
  },
  "timestamp": "2026-03-24T15:30:00+00:00"
}
```

---

## Intégration

### Installation
1. Copier le module dans `/modules/ucp/`
2. Installer depuis l'administration PrestaShop
3. Configurer les clés API et signatures
4. Configurer le serveur web (Apache/Nginx)

### Configuration
- Activer/désactiver les logs de sécurité
- Configurer les seuils de validation
- Personnaliser les messages d'erreur
- Définir la durée de vie des sessions

### Tests
- Utiliser les endpoints de test pour valider l'intégration
- Vérifier la gestion des erreurs
- Tester les scénarios de charge
- Valider la finalisation des sessions

## Monitoring

### Logs disponibles
- Logs PrestaShop intégrés
- Logs de sécurité des requêtes
- Logs d'erreurs détaillés
- Logs de performance

### Métriques à surveiller
- Taux de succès/échec des sessions
- Temps de réponse des endpoints
- Volume de requêtes par client
- Utilisation des codes promo

## Support

Pour toute question ou problème d'intégration:
1. Consulter les logs PrestaShop
2. Vérifier la configuration serveur
3. Tester avec les endpoints de diagnostic
4. Contacter l'équipe de support technique

## Version

- **Version actuelle**: 1.0.0
- **Compatible PrestaShop**: 1.7+
- **PHP requis**: 7.4+
- **Protocole UCP**: 2026-03-13

---

*Module développé selon les spécifications du protocole UCP pour garantir une intégration sécurisée et performante.*

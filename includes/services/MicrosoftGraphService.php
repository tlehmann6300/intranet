<?php
/**
 * Microsoft Graph Service
 * Handles user invitation, role assignment, and user profile photo retrieval via Microsoft Graph API
 * Requires Azure App Permissions: User.Invite.All, AppRoleAssignment.ReadWrite.All, and User.Read.All
 */

// Load Composer autoloader if available (vendor/ is not committed; run composer install on the server)
$_msGraphAutoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($_msGraphAutoload)) {
    require_once $_msGraphAutoload;
}
unset($_msGraphAutoload);
define('MS_GRAPH_VENDOR_AVAILABLE', class_exists('GuzzleHttp\Client'));
require_once __DIR__ . '/../../config/config.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class MicrosoftGraphService {
    
    private $accessToken;
    private $httpClient;
    private $servicePrincipalId;
    
    /**
     * Constructor: Obtain access token via Client Credentials Flow or use provided user token
     * 
     * @param string|null $userAccessToken Optional user access token (from OAuth login)
     * @throws Exception If authentication fails or environment variables are missing
     */
    public function __construct($userAccessToken = null) {
        if (!MS_GRAPH_VENDOR_AVAILABLE) {
            throw new Exception('Microsoft Graph Service requires Composer dependencies. Please run "composer install" on the server.');
        }
        // Initialize Guzzle HTTP client
        $this->httpClient = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
        
        // If a user access token is provided, use it directly
        if ($userAccessToken !== null) {
            $this->accessToken = $userAccessToken;
            return;
        }
        
        // Otherwise, obtain access token using Client Credentials Flow
        // Verify required environment variables are set
        $tenantId = defined('AZURE_TENANT_ID') ? AZURE_TENANT_ID : '';
        $clientId = defined('AZURE_CLIENT_ID') ? AZURE_CLIENT_ID : '';
        $clientSecret = defined('AZURE_CLIENT_SECRET') ? AZURE_CLIENT_SECRET : '';
        
        if (empty($tenantId) || empty($clientId) || empty($clientSecret)) {
            throw new Exception('Azure credentials not configured. Check AZURE_TENANT_ID, AZURE_CLIENT_ID, and AZURE_CLIENT_SECRET in .env file.');
        }
        
        // Obtain access token using Client Credentials Flow
        $this->accessToken = $this->getAccessToken($tenantId, $clientId, $clientSecret);
    }
    
    /**
     * Get access token using Client Credentials Flow
     * 
     * @param string $tenantId Azure Tenant ID
     * @param string $clientId Azure Client ID
     * @param string $clientSecret Azure Client Secret
     * @return string Access token
     * @throws Exception If token request fails
     */
    private function getAccessToken(string $tenantId, string $clientId, string $clientSecret): string {
        $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";
        
        try {
            $response = $this->httpClient->post($tokenUrl, [
                'form_params' => [
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'scope'         => 'https://graph.microsoft.com/.default',
                    'grant_type'    => 'client_credentials',
                ]
            ]);
            
            $body = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($body['access_token'])) {
                throw new Exception('Access token not found in response');
            }
            
            return $body['access_token'];
            
        } catch (GuzzleException $e) {
            throw new Exception('Failed to obtain access token: ' . $e->getMessage());
        }
    }
    
    /**
     * Invite a user via Microsoft Graph API
     * 
     * @param string $email User's email address
     * @param string $name User's display name
     * @param string $redirectUrl URL to redirect user after accepting invitation
     * @return string User ID of the newly invited user
     * @throws Exception If invitation fails
     */
    public function inviteUser(string $email, string $name, string $redirectUrl): string {
        $invitationUrl = 'https://graph.microsoft.com/v1.0/invitations';
        
        try {
            $response = $this->httpClient->post($invitationUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'invitedUserEmailAddress' => $email,
                    'invitedUserDisplayName' => $name,
                    'inviteRedirectUrl' => $redirectUrl,
                    'sendInvitationMessage' => true
                ]
            ]);
            
            $body = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($body['invitedUser']['id'])) {
                throw new Exception('User ID not found in invitation response');
            }
            
            return $body['invitedUser']['id'];
            
        } catch (GuzzleException $e) {
            throw new Exception('Failed to invite user: ' . $e->getMessage());
        }
    }
    
    /**
     * Invite a guest user to Microsoft Entra ID
     * Alias method for inviteUser with consistent naming
     * 
     * @param string $email User's email address
     * @param string $name User's display name
     * @param string $redirectUrl URL to redirect user after accepting invitation
     * @return string User ID of the newly invited user
     * @throws Exception If invitation fails
     */
    public function inviteGuest(string $email, string $name, string $redirectUrl): string {
        return $this->inviteUser($email, $name, $redirectUrl);
    }
    
    /**
     * Assign a role to a user
     * 
     * @param string $userId User ID (Object ID from Azure AD)
     * @param string $roleValue Role value (e.g., 'anwaerter', 'mitglied')
     * @return bool True if role assignment succeeded
     * @throws Exception If role assignment fails or role is invalid
     */
    public function assignRole(string $userId, string $roleValue): bool {
        // Validate role exists in mapping
        if (!isset(ROLE_MAPPING[$roleValue])) {
            throw new Exception("Invalid role value: {$roleValue}");
        }
        
        $roleId = ROLE_MAPPING[$roleValue];
        
        // Validate that role ID has been configured (not using placeholder)
        if (strpos($roleId, 'PLACEHOLDER_') === 0) {
            throw new Exception("Role ID for '{$roleValue}' is not configured. Please update ROLE_MAPPING with actual Azure App Role IDs.");
        }
        
        // Get Service Principal ID (cached)
        $resourceId = $this->getServicePrincipalId();
        
        $assignmentUrl = "https://graph.microsoft.com/v1.0/users/{$userId}/appRoleAssignments";
        
        try {
            $response = $this->httpClient->post($assignmentUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'principalId' => $userId,
                    'resourceId' => $resourceId,
                    'appRoleId' => $roleId
                ]
            ]);
            
            return $response->getStatusCode() === 201;
            
        } catch (GuzzleException $e) {
            throw new Exception('Failed to assign role: ' . $e->getMessage());
        }
    }
    
    /**
     * Get Service Principal ID (Object ID) for the application
     * This ID is cached to avoid repeated API calls
     * 
     * @return string Service Principal Object ID
     * @throws Exception If Service Principal cannot be retrieved
     */
    private function getServicePrincipalId(): string {
        // Return cached value if available
        if ($this->servicePrincipalId !== null) {
            return $this->servicePrincipalId;
        }
        
        $clientId = defined('AZURE_CLIENT_ID') ? AZURE_CLIENT_ID : '';
        $spUrl = "https://graph.microsoft.com/v1.0/servicePrincipals(appId='{$clientId}')";
        
        try {
            $response = $this->httpClient->get($spUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $body = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($body['id'])) {
                throw new Exception('Service Principal ID not found in response');
            }
            
            // Cache the ID
            $this->servicePrincipalId = $body['id'];
            
            return $this->servicePrincipalId;
            
        } catch (GuzzleException $e) {
            throw new Exception('Failed to get Service Principal ID: ' . $e->getMessage());
        }
    }
    
    /**
     * Get user profile from Microsoft Entra ID
     * Fetches user's jobTitle, companyName, and transitiveMemberOf (Groups/Roles)
     * 
     * @param string $userId User ID (Object ID from Azure AD)
     * @return array User profile data with keys: jobTitle, companyName, groups
     * @throws Exception If profile retrieval fails
     */
    public function getUserProfile(string $userId): array {
        // Request user profile with jobTitle and companyName
        $profileUrl = "https://graph.microsoft.com/v1.0/users/{$userId}?\$select=jobTitle,companyName";
        
        try {
            $response = $this->httpClient->get($profileUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $profileData = json_decode($response->getBody()->getContents(), true);
            
            // Extract job title and company name
            $result = [
                'jobTitle' => $profileData['jobTitle'] ?? null,
                'companyName' => $profileData['companyName'] ?? null,
                'groups' => []
            ];
            
            // Get transitive group memberships (includes nested groups)
            $groupsUrl = "https://graph.microsoft.com/v1.0/users/{$userId}/transitiveMemberOf?\$select=id,displayName";
            
            try {
                $groupsResponse = $this->httpClient->get($groupsUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->accessToken,
                        'Content-Type' => 'application/json'
                    ]
                ]);
                
                $groupsData = json_decode($groupsResponse->getBody()->getContents(), true);
                
                // Extract group id and displayName from the response
                if (isset($groupsData['value']) && is_array($groupsData['value'])) {
                    foreach ($groupsData['value'] as $group) {
                        if (isset($group['id']) && isset($group['displayName'])) {
                            $result['groups'][] = [
                                'id' => $group['id'],
                                'displayName' => $group['displayName']
                            ];
                        }
                    }
                }
            } catch (GuzzleException $e) {
                // Log error but don't fail the entire request if groups fetch fails
                error_log("Failed to fetch user groups: " . $e->getMessage());
            }
            
            return $result;
            
        } catch (GuzzleException $e) {
            throw new Exception('Failed to get user profile: ' . $e->getMessage());
        }
    }
    
    /**
     * Get user profile photo from Microsoft Entra ID.
     *
     * The endpoint returns a raw binary image stream on success (HTTP 200).
     * A 404 response means the user has no photo in Entra – in that case
     * null is returned without throwing so callers can fall back gracefully
     * to the default profile image.
     *
     * @param string $identifier User OID or email address
     * @return string|null Raw binary content of the photo, or null if no photo found
     * @throws Exception For non-404 HTTP errors or network-level failures
     */
    public function getUserPhoto(string $identifier): ?string {
        $photoUrl = 'https://graph.microsoft.com/v1.0/users/' . $identifier . '/photo/$value';

        try {
            // Use http_errors => false so 4xx/5xx responses are returned as response
            // objects instead of throwing GuzzleException subclasses.  This avoids
            // calling hasResponse() / getResponse() on the GuzzleException interface,
            // which does not declare those methods (only RequestException does).
            $response = $this->httpClient->get($photoUrl, [
                'headers'     => ['Authorization' => 'Bearer ' . $this->accessToken],
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();

            // Return binary content when the photo exists
            if ($statusCode === 200) {
                return $response->getBody()->getContents();
            }

            // Log non-200 responses for debugging (helps distinguish 404 vs 401 etc.)
            $responseBody = (string) $response->getBody();
            error_log('MicrosoftGraphService::getUserPhoto HTTP ' . $statusCode . ' for ' . $identifier . ': ' . $responseBody);

            // User has no photo in Entra – return null so the caller falls back to
            // the default profile image without any PHP error being raised
            if ($statusCode === 404) {
                return null;
            }

            // Any other non-success status is a genuine API error
            throw new Exception('Failed to get user photo: HTTP ' . $statusCode);

        } catch (GuzzleException $e) {
            // Network-level failures (DNS, connection refused, timeout, …)
            throw new Exception('Failed to get user photo: ' . $e->getMessage());
        }
    }
    
    /**
     * Get the app role names assigned to a user in the Enterprise Application.
     *
     * Calls GET /users/{azureOid}/appRoleAssignments, translates the returned
     * appRoleId UUIDs to role name strings using ROLE_MAPPING, and returns
     * only roles that are known to this application.
     *
     * Use this instead of the JWT 'roles' claim to read directly from the
     * Enterprise Application (Unternehmensanwendung) so that role changes made
     * in the portal are reflected immediately without waiting for a new token.
     *
     * @param string $azureOid User Object ID (OID) from Azure AD
     * @return array  Array of role name strings (e.g. ['mitglied', 'ressortleiter'])
     * @throws Exception If the API request fails
     */
    public function getUserAppRoles(string $azureOid): array {
        $url = "https://graph.microsoft.com/v1.0/users/{$azureOid}/appRoleAssignments";

        try {
            $response = $this->httpClient->get($url, [
                'headers'     => ['Authorization' => 'Bearer ' . $this->accessToken],
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                error_log('MicrosoftGraphService::getUserAppRoles HTTP ' . $statusCode . ' for OID ' . $azureOid . ': ' . (string) $response->getBody());
                return [];
            }

            $body = json_decode($response->getBody()->getContents(), true);

            if (!isset($body['value']) || !is_array($body['value'])) {
                return [];
            }

            // Build reverse mapping: UUID -> role name
            $roleUuidToName = defined('ROLE_MAPPING') ? array_flip(ROLE_MAPPING) : [];

            $roles = [];
            foreach ($body['value'] as $assignment) {
                $appRoleId = $assignment['appRoleId'] ?? null;
                if ($appRoleId !== null && isset($roleUuidToName[$appRoleId])) {
                    $roles[] = $roleUuidToName[$appRoleId];
                }
            }

            return $roles;

        } catch (GuzzleException $e) {
            throw new Exception('Failed to get user app roles: ' . $e->getMessage());
        }
    }

    /**
     * Get current app role assignment ID for a user
     * Retrieves the assignment ID (not the role ID!) of the user's current role
     * that matches one of the roles in ROLE_MAPPING
     * 
     * @param string $userId User ID (Object ID from Azure AD)
     * @return string|null Assignment ID if found, null otherwise
     * @throws Exception If API request fails
     */
    public function getCurrentAppRoleAssignmentId(string $userId): ?string {
        $assignmentsUrl = "https://graph.microsoft.com/v1.0/users/{$userId}/appRoleAssignments";
        
        try {
            $response = $this->httpClient->get($assignmentsUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $body = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($body['value']) || !is_array($body['value'])) {
                return null;
            }
            
            // Get all role IDs from ROLE_MAPPING
            $mappedRoleIds = array_values(ROLE_MAPPING);
            
            // Loop through assignments to find one that matches our ROLE_MAPPING
            foreach ($body['value'] as $assignment) {
                $appRoleId = $assignment['appRoleId'] ?? null;
                
                // Check if this assignment's appRoleId is in our ROLE_MAPPING
                if ($appRoleId && in_array($appRoleId, $mappedRoleIds)) {
                    // Return the assignment ID (this is the ID of the assignment, not the role!)
                    return $assignment['id'] ?? null;
                }
            }
            
            return null;
            
        } catch (GuzzleException $e) {
            throw new Exception('Failed to get current app role assignment: ' . $e->getMessage());
        }
    }
    
    /**
     * Remove a role assignment from a user
     * 
     * @param string $userId User ID (Object ID from Azure AD)
     * @param string $assignmentId Assignment ID to remove
     * @return bool True if removal succeeded
     * @throws Exception If role removal fails
     */
    public function removeRole(string $userId, string $assignmentId): bool {
        $deleteUrl = "https://graph.microsoft.com/v1.0/users/{$userId}/appRoleAssignments/{$assignmentId}";
        
        try {
            $response = $this->httpClient->delete($deleteUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            // DELETE returns 204 No Content on success
            return $response->getStatusCode() === 204;
            
        } catch (GuzzleException $e) {
            throw new Exception('Failed to remove role: ' . $e->getMessage());
        }
    }
    
    /**
     * Update user role - complete role change workflow
     * This method manages the complete role change process:
     * 1. Get current role assignment ID
     * 2. Remove current role if exists
     * 3. Assign new role
     * 
     * @param string $userId User ID (Object ID from Azure AD)
     * @param string $newRoleValue New role value from ROLE_MAPPING (e.g., 'anwaerter', 'mitglied', 'vorstand_finanzen')
     * @return bool True if role update succeeded
     * @throws Exception If role update fails (including if role removal fails, leaving user without role)
     */
    public function updateUserRole(string $userId, string $newRoleValue): bool {
        // Step 1: Get current assignment ID
        $currentAssignmentId = $this->getCurrentAppRoleAssignmentId($userId);
        
        // Step 2: Remove current role if it exists
        // Note: If removal fails, an exception is thrown and user may be left without a role assignment
        // This is intentional to prevent inconsistent states between Azure and local database
        if ($currentAssignmentId !== null) {
            $this->removeRole($userId, $currentAssignmentId);
        }
        
        // Step 3: Assign new role
        return $this->assignRole($userId, $newRoleValue);
    }
    
    /**
     * Get all groups from Microsoft Entra ID
     * 
     * @return array Array of groups with 'id' and 'displayName'
     * @throws Exception If groups retrieval fails
     */
    public function getAllGroups(): array {
        $groupsUrl = "https://graph.microsoft.com/v1.0/groups";
        
        try {
            $response = $this->httpClient->get($groupsUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $body = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($body['value']) || !is_array($body['value'])) {
                return [];
            }
            
            // Extract id and displayName from each group
            $groups = [];
            foreach ($body['value'] as $group) {
                if (isset($group['id']) && isset($group['displayName'])) {
                    $groups[] = [
                        'id' => $group['id'],
                        'displayName' => $group['displayName']
                    ];
                }
            }
            
            return $groups;
            
        } catch (GuzzleException $e) {
            // Return empty array instead of throwing exception for graceful degradation
            error_log('Failed to fetch groups from Microsoft Graph API for event role selection: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Search users in the Azure Tenant by display name or email address
     * 
     * @param string $query Search term (name or email)
     * @return array Array of users with 'id', 'displayName', and 'mail'
     * @throws Exception If search request fails
     */
    public function searchUsers(string $query): array {
        // Sanitize query to prevent OData injection: strip characters that could
        // break out of the single-quoted filter value or inject OData operators.
        $safeQuery = str_replace(["'", '"', '\\', '/', '+', '(', ')'], '', $query);

        $usersUrl = "https://graph.microsoft.com/v1.0/users"
            . "?\$filter=startswith(displayName,'{$safeQuery}') or startswith(mail,'{$safeQuery}')"
            . "&\$select=id,displayName,mail,userType"
            . "&\$top=20";

        try {
            $response = $this->httpClient->get($usersUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type'  => 'application/json',
                    'ConsistencyLevel' => 'eventual',
                ]
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!isset($body['value']) || !is_array($body['value'])) {
                return [];
            }

            $users = [];
            foreach ($body['value'] as $user) {
                $users[] = [
                    'id'          => $user['id'] ?? '',
                    'displayName' => $user['displayName'] ?? '',
                    'mail'        => $user['mail'] ?? '',
                    'userType'    => strtolower($user['userType'] ?? 'member'),
                ];
            }

            return $users;

        } catch (GuzzleException $e) {
            throw new Exception('Failed to search users: ' . $e->getMessage());
        }
    }

    /**
     * Look up a user in Microsoft Entra by their e-mail address.
     *
     * @param string $email E-mail address to search for
     * @return array|null User data array with at least 'id', 'displayName', 'mail',
     *                    or null if no account with that address was found
     * @throws Exception If the API request fails for any reason other than "not found"
     */
    public function getUserByEmail(string $email): ?array {
        // Escape the e-mail for use inside an OData single-quoted string literal.
        // The only character that can break out of the literal is a single quote;
        // the OData specification requires it to be doubled ('').
        $safeEmail = str_replace("'", "''", $email);

        $url = "https://graph.microsoft.com/v1.0/users"
             . "?\$filter=mail eq '{$safeEmail}' or userPrincipalName eq '{$safeEmail}'"
             . "&\$select=id,displayName,mail,userPrincipalName,accountEnabled"
             . "&\$top=1";

        try {
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization'    => 'Bearer ' . $this->accessToken,
                    'Content-Type'     => 'application/json',
                    'ConsistencyLevel' => 'eventual',
                ]
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!isset($body['value']) || !is_array($body['value']) || count($body['value']) === 0) {
                return null;
            }

            $user = $body['value'][0];

            return [
                'id'               => $user['id'] ?? '',
                'displayName'      => $user['displayName'] ?? '',
                'mail'             => $user['mail'] ?? '',
                'userPrincipalName'=> $user['userPrincipalName'] ?? '',
                'accountEnabled'   => $user['accountEnabled'] ?? true,
            ];

        } catch (GuzzleException $e) {
            throw new Exception('Failed to look up user by e-mail: ' . $e->getMessage());
        }
    }

    /**
     * Disable a Microsoft Entra user account by e-mail address.
     * Sets accountEnabled = false, which blocks sign-in immediately.
     *
     * @param string $email E-mail address of the account to disable
     * @return bool True if the account was found and disabled, false if no account exists
     * @throws Exception If the API request fails for any reason other than "not found"
     */
    public function disableUserByEmail(string $email): bool {
        $user = $this->getUserByEmail($email);

        if ($user === null) {
            return false; // No account – nothing to disable
        }

        $userId  = $user['id'];
        $patchUrl = "https://graph.microsoft.com/v1.0/users/{$userId}";

        try {
            $this->httpClient->patch($patchUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => ['accountEnabled' => false],
            ]);

            return true;

        } catch (GuzzleException $e) {
            // 404 means the user was removed between the lookup and the PATCH –
            // treat this as "nothing to disable" instead of a fatal error.
            if ($e->hasResponse() && $e->getResponse()->getStatusCode() === 404) {
                return false;
            }
            throw new Exception('Failed to disable user account: ' . $e->getMessage());
        }
    }

    /**
     * Invite an external guest user via Microsoft Entra B2B Collaboration.
     * A guest invitation e-mail is sent to the address. Returns the new user's
     * Object ID so callers can add the account to groups straight away.
     *
     * @param string $email     Guest's e-mail address
     * @param string $firstName Guest's first name
     * @param string $lastName  Guest's last name
     * @return string Object ID of the newly created guest account
     * @throws Exception If the invitation API call fails
     */
    public function inviteGuestUser(string $email, string $firstName, string $lastName): string {
        $displayName   = trim($firstName . ' ' . $lastName);
        $redirectUrl   = defined('BASE_URL') ? BASE_URL : 'https://intra.business-consulting.de';
        $invitationUrl = 'https://graph.microsoft.com/v1.0/invitations';

        try {
            $response = $this->httpClient->post($invitationUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'invitedUserEmailAddress'    => $email,
                    'invitedUserDisplayName'     => $displayName,
                    'inviteRedirectUrl'          => $redirectUrl,
                    'sendInvitationMessage'      => true,
                    'invitedUserType'            => 'Guest',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!isset($body['invitedUser']['id'])) {
                throw new Exception('User ID not found in guest invitation response');
            }

            return $body['invitedUser']['id'];

        } catch (GuzzleException $e) {
            throw new Exception('Failed to invite guest user: ' . $e->getMessage());
        }
    }

    /**
     * Add a user to a Microsoft Entra group (e.g. an e-mail distribution list).
     *
     * @param string $userId  Object ID of the user to add
     * @param string $groupId Object ID of the target group
     * @return bool True if the user was added (204) or was already a member (400 with "already exists" error)
     * @throws Exception If the API call returns any other status code
     */
    public function addUserToGroup(string $userId, string $groupId): bool {
        $membersUrl = "https://graph.microsoft.com/v1.0/groups/{$groupId}/members/\$ref";

        $response = $this->httpClient->post($membersUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                '@odata.id' => "https://graph.microsoft.com/v1.0/directoryObjects/{$userId}",
            ],
            'http_errors' => false,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 204) {
            return true;
        }

        $responseBody = (string) $response->getBody();

        if ($statusCode === 400) {
            $errorBody = json_decode($responseBody, true);
            $errorMsg  = is_array($errorBody) ? ($errorBody['error']['message'] ?? '') : '';
            if (stripos($errorMsg, 'already exists') !== false) {
                return true; // Already a member – idempotent success
            }
        }

        throw new Exception(
            "Failed to add user to group: HTTP {$statusCode} – {$responseBody}"
        );
    }

    /**
     * Get direct (non-transitive) group and directory-role memberships via the /memberOf API.
     *
     * When $userId is provided the method uses the application token (client-credentials flow)
     * and calls /users/{userId}/memberOf – suitable for server-side checks during login.
     * When $userId is omitted the method calls /me/memberOf and requires a delegated user token
     * with GroupMember.Read.All permission.
     *
     * @param string|null $userId Azure Object ID of the user (omit to use /me)
     * @return array Array of objects with 'id' and 'displayName'
     * @throws Exception If the Graph API call fails
     */
    public function getMemberOf(?string $userId = null): array {
        $url = $userId !== null
            ? 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userId) . '/memberOf?$select=id,displayName'
            : 'https://graph.microsoft.com/v1.0/me/memberOf?$select=id,displayName';

        try {
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type'  => 'application/json',
                ]
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!isset($body['value']) || !is_array($body['value'])) {
                return [];
            }

            $groups = [];
            foreach ($body['value'] as $entry) {
                if (isset($entry['id']) && isset($entry['displayName'])) {
                    $groups[] = [
                        'id'          => $entry['id'],
                        'displayName' => $entry['displayName'],
                    ];
                }
            }

            return $groups;

        } catch (GuzzleException $e) {
            throw new Exception('Failed to fetch /memberOf groups: ' . $e->getMessage());
        }
    }

    /**
     * Get member groups for the current user (requires user access token)
     * This method uses the /me endpoint and requires a user access token (not service account)
     * 
     * @return array Array of groups with 'id' and 'displayName'
     * @throws Exception If groups retrieval fails
     */
    public function getMemberGroups(): array {
        $groupsUrl = "https://graph.microsoft.com/v1.0/me/transitiveMemberOf?\$select=id,displayName";
        
        try {
            $response = $this->httpClient->get($groupsUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json'
                ]
            ]);
            
            $body = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($body['value']) || !is_array($body['value'])) {
                return [];
            }
            
            // Extract id and displayName from each group
            $groups = [];
            foreach ($body['value'] as $group) {
                if (isset($group['id']) && isset($group['displayName'])) {
                    $groups[] = [
                        'id' => $group['id'],
                        'displayName' => $group['displayName']
                    ];
                }
            }
            
            return $groups;
            
        } catch (GuzzleException $e) {
            throw new Exception('Failed to fetch member groups: ' . $e->getMessage());
        }
    }
}

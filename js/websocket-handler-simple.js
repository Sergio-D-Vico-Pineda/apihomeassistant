// Simple WebSocket handler for Home Assistant real-time updates
class WebSocketHandler {
    constructor(haUrl, accessToken) {
        this.wsUrl = haUrl.replace('http', 'ws') + '/api/websocket';
        this.accessToken = accessToken;
        this.messageId = 1;
        this.subscriptions = new Map();
        this.isAuthenticated = false;
        this.readyCallbacks = [];
        this.onStatusChange = (status) => {
            const wsStatus = document.getElementById('websocket-status');
            wsStatus.textContent = `WebSocket: ${status}`;
        };
    }

    connect() {
        this.ws = new WebSocket(this.wsUrl);
        console.log('Connecting to WebSocket');
        this.onStatusChange('Connecting');

        this.ws.onopen = () => {
            console.log('Connected');
            this.onStatusChange('Connected');
            this.authenticate();
        };

        this.ws.onmessage = (event) => {
            const data = JSON.parse(event.data);
            this.handleMessage(data);
        };

        this.ws.onclose = () => {
            this.onStatusChange('Disconnected');
            this.isAuthenticated = false;
        };

        this.ws.onerror = (error) => {
            console.error('WebSocket error:', error);
            this.onStatusChange('Error');
        };
    }

    authenticate() {
        const authMessage = {
            type: 'auth',
            access_token: this.accessToken
        };
        this.sendMessage(authMessage);
    }

    isReady() {
        return this.ws && this.ws.readyState === WebSocket.OPEN && this.isAuthenticated;
    }

    whenReady(callback) {
        console.log('Checking if WebSocket is ready');
        if (this.isReady()) {
            callback();
        } else {
            this.readyCallbacks.push(callback);
        }
    }

    // Subscribe to specific entities using triggers (efficient)
    subscribeToStates(entityIds, callback) {
        console.log('Subscribing to entities:', entityIds);
        const subscriptionIds = [];

        entityIds.forEach(entityId => {
            const subscriptionId = this.messageId++;

            const message = {
                id: subscriptionId,
                type: 'subscribe_trigger',
                trigger: {
                    platform: 'state',
                    entity_id: entityId
                }
            };

            this.subscriptions.set(subscriptionId, {
                entityId: entityId,
                callback: callback,
                type: 'trigger'
            });

            this.sendMessage(message);
            subscriptionIds.push(subscriptionId);
        });

        return subscriptionIds;
    }

    sendMessage(message) {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify(message));
            return true;
        }
        return false;
    }

    handleMessage(data) {
        if (data.type === 'auth_ok') {
            console.log('Authentication successful');
            this.isAuthenticated = true;
            this.readyCallbacks.forEach(callback => callback());
            this.readyCallbacks = [];
            return;
        }

        if (data.type === 'auth_invalid') {
            console.error('Authentication failed:', data.message);
            return;
        }

        if (data.type === 'event') {
            const subscription = this.subscriptions.get(data.id);

            if (subscription && subscription.callback) {
                if (subscription.type === 'trigger') {
                    // Handle trigger events
                    const triggerData = {
                        entity_id: subscription.entityId,
                        new_state: data.event.variables.trigger.to_state,
                        old_state: data.event.variables.trigger.from_state
                    };
                    subscription.callback(triggerData);
                }
            }
        }
    }

    callService(domain, service, entityId) {
        const message = {
            id: this.messageId++,
            type: 'call_service',
            domain: domain,
            service: service,
            target: {
                entity_id: entityId
            }
        };
        return this.sendMessage(message);
    }

    close() {
        if (this.ws) {
            this.ws.close();
        }
    }
}

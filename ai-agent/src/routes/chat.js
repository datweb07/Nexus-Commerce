import { Router } from 'express';

const router = Router();

// Stub — full implementation in task 5.3
router.post('/', (req, res) => {
  res.status(501).json({ error: 'Not implemented' });
});

export default router;
